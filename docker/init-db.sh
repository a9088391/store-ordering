#!/usr/bin/env bash
set -euo pipefail

# 只補建本案預先約定的資料庫與角色，拒絕意外的環境設定。
case "$POSTGRES_DB" in
    store_ordering|store_ordering_test) ;;
    *)
        printf '拒絕初始化非本案的 PostgreSQL 資料庫。\n' >&2
        exit 2
        ;;
esac

# 鎖定整個補建流程，任一步驟中斷後都能用相同設定重跑。
ready=$(psql --no-psqlrc --quiet --tuples-only --no-align \
    --username postgres --dbname postgres --set=ON_ERROR_STOP=1 \
    --set=database="$POSTGRES_DB" <<'SQL'
\o /dev/null
SET log_min_error_statement = 'panic';
SELECT pg_advisory_lock(3, 1);

-- 只為缺少的角色設定原始密碼，重跑時保留既有憑證。
\getenv app_password STORE_ORDERING_DB_PASSWORD
SELECT format('CREATE ROLE store_ordering LOGIN PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'store_ordering')
\gexec

-- 確認既有角色符合原定權限，避免把資料庫交給意外的管理角色。
SELECT EXISTS (
    SELECT FROM pg_roles
    WHERE rolname = 'store_ordering'
        AND rolcanlogin
        AND NOT rolsuper
        AND NOT rolcreaterole
        AND NOT rolcreatedb
        AND NOT rolreplication
        AND NOT rolbypassrls
) AS app_role_valid
\gset

\if :app_role_valid
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
\endif

-- 驗證本次模式需要的所有資源，避免回報不完整的啟動結果。
\o
SELECT :'app_role_valid'::boolean
    AND EXISTS (
        SELECT FROM pg_database
        WHERE datname = :'database' AND pg_get_userbyid(datdba) = 'store_ordering'
    )
    AND (
        :'database' <> 'store_ordering_test'
        OR EXISTS (
            SELECT FROM pg_database
            WHERE datname = 'store_ordering_phpunit' AND pg_get_userbyid(datdba) = 'store_ordering'
        )
    );
SQL
)

if [ "$ready" != t ]; then
    printf '本案 PostgreSQL 角色或資料庫 owner 不符，請先檢查既有設定。\n' >&2
    exit 1
fi
