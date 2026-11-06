#!/usr/bin/env bash
set -euo pipefail

# 只補建本案預先約定的資料庫與角色，拒絕意外的環境設定。
case "$POSTGRES_DB:$STORE_ORDERING_MOCK_PAYMENT_DATABASE" in
    store_ordering:store_ordering_mock_payment|store_ordering_test:store_ordering_mock_payment_test) ;;
    *)
        printf '拒絕初始化非本案的 PostgreSQL 資料庫。\n' >&2
        exit 2
        ;;
esac
: "${STORE_ORDERING_MOCK_PAYMENT_DB_PASSWORD:?缺少模擬金流資料庫密碼}"

# 鎖定整個補建流程，任一步驟中斷後都能用相同設定重跑。
ready=$(psql --no-psqlrc --quiet --tuples-only --no-align \
    --username postgres --dbname postgres --set=ON_ERROR_STOP=1 \
    --set=database="$POSTGRES_DB" --set=mock_database="$STORE_ORDERING_MOCK_PAYMENT_DATABASE" <<'SQL'
\o /dev/null
SET log_min_error_statement = 'panic';
SELECT pg_advisory_lock(3, 1);

-- 只為缺少的角色設定原始密碼，重跑時保留既有憑證。
\getenv app_password STORE_ORDERING_DB_PASSWORD
SELECT format('CREATE ROLE store_ordering LOGIN PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'store_ordering')
\gexec
\getenv mock_password STORE_ORDERING_MOCK_PAYMENT_DB_PASSWORD
SELECT format('CREATE ROLE store_ordering_mock_payment LOGIN PASSWORD %L', :'mock_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'store_ordering_mock_payment')
\gexec

-- 確認既有角色沒有管理權限，也不能互相切換角色。
SELECT count(*) = 2
    AND NOT pg_has_role('store_ordering', 'store_ordering_mock_payment', 'MEMBER')
    AND NOT pg_has_role('store_ordering_mock_payment', 'store_ordering', 'MEMBER') AS roles_valid
FROM pg_roles
WHERE rolname IN ('store_ordering', 'store_ordering_mock_payment')
    AND rolcanlogin
    AND NOT rolsuper
    AND NOT rolcreaterole
    AND NOT rolcreatedb
    AND NOT rolreplication
    AND NOT rolbypassrls
\gset

\if :roles_valid
-- 官方初始化若在建立主要資料庫前中斷，從已可用的 PostgreSQL 補建該資料庫。
SELECT format('CREATE DATABASE %I OWNER store_ordering', :'database')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'database')
\gexec

-- 只有 PostgreSQL 首次建立而尚未移交的主要資料庫需要改 owner。
SELECT format('ALTER DATABASE %I OWNER TO store_ordering', datname)
FROM pg_database
WHERE datname = :'database' AND pg_get_userbyid(datdba) = 'postgres'
\gexec

-- 測試流程另需一個可執行遷移的資料庫，已存在時保留。
SELECT 'CREATE DATABASE store_ordering_phpunit OWNER store_ordering'
WHERE :'database' = 'store_ordering_test'
    AND NOT EXISTS (SELECT FROM pg_database WHERE datname = 'store_ordering_phpunit')
\gexec

-- 模擬服務只擁有自己的資料庫，不透過跨庫關聯取得訂單資料。
SELECT format('CREATE DATABASE %I OWNER store_ordering_mock_payment', :'mock_database')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'mock_database')
\gexec

-- 預設 PUBLIC 能連線；明確撤除並核對相反角色（也包含 PHPUnit 資料庫）。
SELECT format('REVOKE CONNECT, TEMPORARY ON DATABASE %I FROM PUBLIC, store_ordering_mock_payment', datname)
FROM pg_database WHERE datname = :'database' OR (:'database' = 'store_ordering_test' AND datname = 'store_ordering_phpunit')
\gexec
SELECT format('REVOKE CONNECT, TEMPORARY ON DATABASE %I FROM PUBLIC, store_ordering', :'mock_database')
\gexec
\endif

-- 驗證本次模式需要的所有資源，避免回報不完整的啟動結果。
\o
SELECT :'roles_valid'::boolean
    AND EXISTS (
        SELECT FROM pg_database
        WHERE datname = :'database' AND pg_get_userbyid(datdba) = 'store_ordering'
            AND NOT has_database_privilege('store_ordering_mock_payment', oid, 'CONNECT')
    )
    AND EXISTS (
        SELECT FROM pg_database
        WHERE datname = :'mock_database' AND pg_get_userbyid(datdba) = 'store_ordering_mock_payment'
            AND NOT has_database_privilege('store_ordering', oid, 'CONNECT')
    )
    AND (
        :'database' <> 'store_ordering_test'
        OR EXISTS (
            SELECT FROM pg_database
            WHERE datname = 'store_ordering_phpunit' AND pg_get_userbyid(datdba) = 'store_ordering'
                AND NOT has_database_privilege('store_ordering_mock_payment', oid, 'CONNECT')
        )
    );
SQL
)

if [ "$ready" != t ]; then
    printf '本案 PostgreSQL 角色或資料庫 owner 不符，請先檢查既有設定。\n' >&2
    exit 1
fi
