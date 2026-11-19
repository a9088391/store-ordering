# 架構與 API

瀏覽器從訂單應用載入 Vue 頁面並呼叫同源 API。訂單應用保存帳號、庫存、訂單、付款意圖及交易歷程。獨立的模擬金流服務保存付款結果、通知事件與派送待辦，以內部 HTTP 回應查詢及送出簽章通知。兩個服務各自使用 PostgreSQL 資料庫與服務帳號。通知與人工查詢共用結算路徑，在一個訂單資料庫交易內更新訂單、庫存與歷程。

- `backend/routes/web.php` 註冊同源 JSON API。登入與登出要帶 `GET /api/session` 回傳的 `csrfToken`，以 `X-CSRF-TOKEN` 送出。登入和登出回應會提供新的 token。
- 請求本文最多 64 KiB。PHP 原生解析的 multipart POST 需要可確認的 `Content-Length`。超限或長度無法確認時回傳 `413 PAYLOAD_TOO_LARGE`。
- Laravel 的 session guard 負責身分，查詢套用門市範圍，PostgreSQL 保障庫存資料約束。庫存資料以明確欄位回傳，後端逐次檢查門市存取權。資料庫 migration 與 PHP HTTP 測試位於 `backend/`。
- `POST /api/orders` 接受含 `storeId`、`operationKey` 和 `items` 的 JSON，明細包含 `productId` 與 `quantity`，最多 100 個不同商品。數值須為 JSON 正整數，最高 `9007199254740991`。同一使用者以相同鍵及內容重送會回原訂單。
- 訂單清單的 HTTP 入口驗證分頁與篩選 DTO，訂單查詢負責門市範圍、日期與固定排序，最後以回應 DTO 序列化。
- `GET /api/orders` 可用 `storeId`、`status`、`orderNumber`、`from`、`to`、`page` 和 `perPage` 查詢。`GET /api/orders/{id}` 回傳單筆詳情。`POST /api/orders/{id}/cancel` 取消有效的待付款訂單。所有瀏覽器 POST 都須帶 CSRF token。
- `POST /api/orders/{id}/payment` 發起付款，`POST /api/orders/{id}/payment/query` 查詢並同步同一付款。訂單應用先保存意圖與固定識別，再呼叫模擬金流，每次外呼最長 3 秒。逾時、查無付款或服務無法連線時，可用人工查詢恢復。
- 訂單排程器每分鐘執行 `store-ordering:recover-payments`，以原付款識別補送尚未確認的建立請求。PostgreSQL 在送出前記下已用次數，程序在此時中斷也會占用一次。同一付款最多領取三次額度，每次領取至少間隔一分鐘。確認已建立後會停止補辦，後續結果可用人工查詢同步。
- 總部以 `GET /api/mock-payments` 查詢模擬付款，以 `POST /api/mock-payments/{paymentId}/decision` 提交 `{"status":"paid"}` 或 `{"status":"failed"}`。結果與固定事件一起保存，並可透過 `POST /api/mock-payments/{paymentId}/dispatch` 提交 `{"mode":"immediate"}` 或 `{"mode":"delayed"}`。`202` 回應包含事件識別 `eventId` 及 `mode`，代表已排入派送。既有終局付款在首次派送或重送相同決定時補建事件。
- 模擬金流排程器在啟動當下開始排程，每秒可領取到期通知，每次最多四筆。每筆外呼最多三秒，領取後若尚未記錄成功回應，30 秒租期結束即重新可送。每次嘗試用當下的 Unix 秒數重新簽章，事件識別與原始本文固定。訂單應用的 `POST /api/payment-notifications` 驗證 `X-Payment-Timestamp` 與 `X-Payment-Signature`，簽章為 `timestamp.rawBody` 的 HMAC-SHA256 小寫十六進位，接受前後五分鐘。此機器入口使用驗簽，瀏覽器寫入仍使用 CSRF token。
- 通知使用 `Content-Type: application/json`，必填欄位各出現一次。`eventId` 與 `paymentId` 為 UUID 字串。`orderId` 與 `amount` 為 JSON 正整數，上限 `9007199254740991`。`currency` 為字串 `TWD`，`status` 為終局字串 `paid` 或 `failed`。

  ```json
  {
    "eventId": "b76a4017-0c6d-4c77-96d1-86c7412e15ae",
    "paymentId": "5c101ace-03ca-4809-bcca-25cb78543f1b",
    "orderId": 42,
    "amount": 90,
    "currency": "TWD",
    "status": "paid"
  }
  ```

- 通知與查詢都會核對固定付款綁定，並使用同一訂單交易完成結果。通知成功回傳 `200`，本文為 `{"data":{"eventId":"b76a4017-0c6d-4c77-96d1-86c7412e15ae"}}`。相同事件及本文沿用首次結果。簽章或傳送時間驗證失敗回傳 `403`。本文格式、欄位或事件識別不合法回傳 `422`。事件識別明確且已收過時，異內容即使帶有其他錯誤欄位也會回傳 `409` 並留下異常。付款綁定不符或相反終局也回傳 `409`。具可信付款綁定的異常顯示在原訂單，無法確定綁定時只保留內部稽核紀錄。
- 到期排程每分鐘檢查待付款訂單。庫存查詢與新訂單會依資料庫時間即時處理該店的到期保留；逾期的同鍵重送會先釋放該單並回傳已到期詳情。建單、取消、到期、付款啟動及結果套用使用同一門市的交易鎖，同步訂單、庫存與歷程。處理中的付款繼續保留庫存。
- Session 閒置 30 分鐘或登入滿 8 小時後失效。每 60 秒，同一帳號及同一連線來源各可嘗試登入 5 次。超限時回傳 `429 LOGIN_THROTTLED` 和 `Retry-After`。
- Vue 從門市工作區切換庫存、訂單與總部模擬付款。訂單工作區維護目前門市、篩選、分頁和待確認操作，清單、新增與明細由各自的頁面元件呈現。Vite 建置時執行 TypeScript 型別檢查並產生 Laravel 讀取的資產 manifest。修改前端或後端後，用 `up` 重建對應映像。
- 展示與測試使用不同 Compose 專案、資料庫容器、volume、應用程式密鑰、通知簽章金鑰與 session cookie 名稱。各專案上限為 2 CPU、3 GiB，兩組同時啟動合計上限為 4 CPU、6 GiB。PostgreSQL 及模擬金流僅在各自的 Docker 網路內提供服務。訂單與模擬金流各自擁有資料庫與服務帳號，兩者透過 HTTP 交換付款資料。首次升級執行 `up` 會在各模式的 `.local/` 設定內補建 `payment-notifications.env`，並保留既有應用密鑰、帳密和資料。簽章金鑰只提供給模擬金流排程器和訂單網站。
