-- 同一份查詢讀取測前基準及測後狀態；子查詢保留順序與重複列，便於逐張檢查異動。
WITH recent AS MATERIALIZED (
    SELECT * FROM orders WHERE id > :cutoff OR operation_key NOT LIKE 'perf-history-%'
)
SELECT json_build_object(
    'history', (
        SELECT json_build_array(count(*), max(id),
            md5(string_agg(row_to_json(orders)::text, E'\n' ORDER BY id)))
        FROM orders WHERE operation_key LIKE 'perf-history-%' AND id <= :cutoff
    ),
    'inventory', (SELECT json_agg(stock ORDER BY store_id, product_id) FROM store_inventory AS stock),
    'orders', COALESCE((
        SELECT json_agg(json_build_object(
            'id', recent.id,
            'store', recent.store_id,
            'actor', recent.created_by,
            'operation_key', recent.operation_key,
            'status', recent.status,
            'total', recent.total,
            'expiry_seconds', extract(epoch FROM recent.expires_at - recent.created_at)::int,
            'items', (SELECT json_agg(json_build_array(product_id, name, quantity, unit_price, subtotal)
                ORDER BY product_id) FROM order_items WHERE order_id = recent.id),
            'history', (SELECT json_agg(status ORDER BY id) FROM order_history WHERE order_id = recent.id),
            'ledger', (SELECT json_agg(json_build_array(product_id, quantity, type) ORDER BY id)
                FROM stock_ledger WHERE order_id = recent.id)
        ) ORDER BY recent.id) FROM recent
    ), '[]'),
    'intents', COALESCE((SELECT json_agg(intent ORDER BY order_id) FROM payment_intents AS intent), '[]'),
    'receipts', COALESCE((SELECT json_agg(receipt ORDER BY event_id) FROM payment_notification_receipts AS receipt), '[]'),
    'anomalies', COALESCE((SELECT json_agg(anomaly ORDER BY id) FROM payment_anomalies AS anomaly), '[]'),
    'active_orders', (SELECT count(*) FROM orders WHERE status IN ('awaiting_payment', 'processing'))
);
