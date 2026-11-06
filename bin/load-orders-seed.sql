BEGIN;

-- 鎖住本次會寫入的表後再核對空白基準，防止另一個寫入者混入部分測試資料。
LOCK TABLE orders, order_items, order_history, stock_ledger, payment_intents,
    payment_anomalies, payment_notification_receipts, store_inventory IN SHARE ROW EXCLUSIVE MODE;

DO $$
BEGIN
    IF EXISTS (SELECT FROM orders)
        OR EXISTS (SELECT FROM order_items)
        OR EXISTS (SELECT FROM order_history)
        OR EXISTS (SELECT FROM stock_ledger)
        OR EXISTS (SELECT FROM payment_intents)
        OR EXISTS (SELECT FROM payment_anomalies)
        OR EXISTS (SELECT FROM payment_notification_receipts)
        OR (SELECT count(*) FROM users) <> 3
        OR (SELECT count(*) FROM stores) <> 2
        OR (SELECT count(*) FROM products) <> 2
        OR (SELECT count(*) FROM store_inventory) <> 4
        OR (SELECT count(*) FROM users JOIN stores ON users.store_id = stores.id
            WHERE users.role = 'staff' AND (users.email, stores.name) IN
                (('taipei@example.test', '台北門市'), ('taichung@example.test', '台中門市'))) <> 2
        OR NOT EXISTS (SELECT FROM users WHERE email = 'hq@example.test'
            AND role = 'headquarters' AND store_id IS NULL)
        OR (SELECT count(*) FROM store_inventory AS stock
            JOIN stores ON stores.id = stock.store_id
            JOIN products ON products.id = stock.product_id
            WHERE (stores.name, products.name, products.price, stock.on_hand) IN
                (('台北門市', '濾掛咖啡', 45, 12), ('台中門市', '濾掛咖啡', 45, 4),
                 ('台北門市', '燕麥餅乾', 60, 3), ('台中門市', '燕麥餅乾', 60, 0))) <> 4
    THEN
        RAISE EXCEPTION USING ERRCODE = 'P0002', MESSAGE = '負載測試需要全新的示範資料';
    END IF;
END $$;

-- 兩店各產生 5,000 筆已取消或到期的訂單，保留原始商品快照及完整的保留／釋放紀錄。
WITH sample AS (
    SELECT users.id AS actor, users.store_id, products.id AS product_id, products.name, products.price,
        1 + n % 3 AS quantity,
        'perf-history-' || n AS operation_key,
        CASE WHEN n / 2 % 2 = 0 THEN 'cancelled' ELSE 'expired' END AS status,
        transaction_timestamp() - interval '1 day' - n * interval '1 minute' AS created_at
    FROM generate_series(0, 9999) AS n
    JOIN users ON users.email = CASE WHEN n % 2 = 0 THEN 'taipei@example.test' ELSE 'taichung@example.test' END
    JOIN products ON products.name = CASE WHEN n / 4 % 2 = 0 THEN '濾掛咖啡' ELSE '燕麥餅乾' END
), created AS (
    INSERT INTO orders (store_id, created_by, operation_key, status, total, created_at, expires_at)
    SELECT store_id, actor, operation_key, status, price * quantity, created_at, created_at + interval '15 minutes'
    FROM sample
    ORDER BY created_at
    RETURNING id, operation_key, status, total, created_at, expires_at
), details AS (
    INSERT INTO order_items (order_id, product_id, name, quantity, unit_price, subtotal)
    SELECT created.id, sample.product_id, sample.name, sample.quantity, sample.price, created.total
    FROM created JOIN sample USING (operation_key)
    RETURNING order_id, product_id, quantity
), timeline AS (
    SELECT id, status, created_at, CASE WHEN status = 'expired' THEN expires_at
        ELSE created_at + interval '5 minutes' END AS closed_at
    FROM created
), history AS (
    INSERT INTO order_history (order_id, status, occurred_at)
    SELECT id, event.status, event.occurred_at FROM timeline
    CROSS JOIN LATERAL (VALUES (1, 'awaiting_payment', created_at), (2, status, closed_at))
        AS event(step, status, occurred_at)
    ORDER BY id, event.step
)
INSERT INTO stock_ledger (order_id, product_id, quantity, type, occurred_at)
SELECT id, product_id, quantity, event.type, event.occurred_at FROM timeline
JOIN details ON details.order_id = timeline.id
CROSS JOIN LATERAL (VALUES (1, 'reserved', created_at), (2, 'released', closed_at))
    AS event(step, type, occurred_at)
ORDER BY id, event.step;

-- 只在隔離資料庫調高負載品項的實存量；保留另一品項用於檢查是否發生意外扣庫。
UPDATE store_inventory AS stock
SET on_hand = CASE WHEN stores.name = '台北門市' THEN 1000 ELSE 1100 END
FROM products, stores
WHERE products.id = stock.product_id AND stores.id = stock.store_id AND products.name = '濾掛咖啡';

COMMIT;

-- 讓正式查詢使用已反映歷史資料量的統計資訊。
ANALYZE orders;
ANALYZE order_items;
ANALYZE order_history;
ANALYZE stock_ledger;
