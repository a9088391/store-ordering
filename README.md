# 門市訂單系統

面試用 side project，展示總部與門市人員如何查看庫存、建立多品項訂單、保留庫存，以及透過模擬金流完成付款。總部可切換兩店，店員使用本店資料。可售庫存是實體庫存扣除有效保留後的數量。

技術採 Laravel 13、PHP 8.5、Vue 3、TypeScript、Vite 與 PostgreSQL 18。Laravel 提供訂單 API 與前端靜態檔，瀏覽器透過同一個來源登入及操作。獨立的模擬金流服務透過內部 HTTP 與訂單應用通訊。這個版本的規格拆分、實作與驗證採 AI 協作，依 API、真實服務及瀏覽器各自檢查對應行為。

## 本機啟動

在專案根目錄執行下列命令。本機驗收環境是 Apple Silicon Mac、使用本機 Unix socket 的 Docker Engine、Docker Compose、`openssl` 與桌面版 Chrome 或 Chromium。首次建置需連線取得官方容器映像及鎖檔中的套件。預設展示埠為 `127.0.0.1:8010`，請先確認該埠可用。

```sh
./bin/local demo up
```

在本機開啟 [門市訂單系統](http://127.0.0.1:8010)。用本機編輯器開啟 `.local/demo/accounts.json` 取得隨機初始密碼。三個帳號為：

| 帳號 | 可查閱門市 |
| --- | --- |
| `hq@example.test` | 台北門市、台中門市 |
| `taipei@example.test` | 台北門市 |
| `taichung@example.test` | 台中門市 |

登入後可在「門市庫存」查閱示範品項。新環境的實體與可售庫存相同。既有環境請先記下畫面的實際數量。

| 商品 | 單價（TWD） | 台北初始庫存 | 台中初始庫存 |
| --- | ---: | ---: | ---: |
| 濾掛咖啡 | 45 | 12 | 4 |
| 燕麥餅乾 | 60 | 3 | 0 |

### 展示路線

以下數字以初始庫存為例。要在隔離站練習，可先執行 `./bin/local test up`，改用 [8011 測試站](http://127.0.0.1:8011) 及 `.local/test/accounts.json`。切換帳號時先從頁首登出。

1. **台北店員建立多品項訂單。**在「建立訂單」依序選濾掛咖啡及燕麥餅乾，各按一次「新增品項」，分別填 2、1，按「建立訂單」。記下訂單編號。詳情應顯示待付款、總額 150 元及兩筆保留，咖啡與餅乾的實體／可售庫存分別是 12／10、3／2。按「發起付款」，記下付款識別，確認訂單進入付款處理中，庫存維持原值。
2. **台中店員確認隔離與缺貨。**庫存應顯示咖啡 4／4、餅乾 0／0，畫面只有台中門市，且沒有總部模擬付款面板。以台北的訂單編號套用篩選，應查不到訂單。清除篩選，嘗試各買 1 件咖啡及餅乾。應收到可售庫存不足，兩個品項數量與訂單清單均不變。移除餅乾、重新建立 1 件咖啡的訂單並發起付款。記下台中訂單編號與付款識別，咖啡應為 4／3。
3. **總部完成成功付款並重送。**在「總部模擬付款」按「重新載入付款」，以台北訂單編號與付款識別核對同一列，按「決定成功」再按「立即送出／重送」，記下畫面顯示的通知事件識別。切至台北門市，在「訂單清單」依編號套用篩選，點「查看訂單 #編號」。若通知尚在排程，稍候再開同一筆詳情。訂單與付款應同為「已付款」，兩品項各有一筆保留、扣除及釋放。重新整理頁面並選台北門市，庫存應為 10／10、2／2。再次按同列的「立即送出／重送」，應顯示相同的事件識別。在測試站可依「派送與重啟核對」確認第二次實際送達，再重新整理、開詳情，比對歷程與庫存數量。
4. **總部確認失敗、重啟及延遲通知。**在台中訂單對應的付款列按「決定失敗」，再按「延遲 60 秒送出」並記下事件識別。在通知送達前查看台中訂單，應仍為付款處理中且咖啡為 4／3。依本頁的重啟命令操作，測試站使用 `./bin/local test restart`。自排入通知約 60 秒後，重新整理頁面、選台中門市，再開同一筆詳情。應顯示同一付款識別，訂單及付款均為「付款失敗」，只有一筆保留與一筆釋放，台中咖啡回到 4／4。台北的成功訂單、歷程與扣庫存結果應保留。用測試手冊比對重啟與送達時間。先確認通知已結算，再試「查詢付款結果」，因為人工查詢也會同步結果。
5. **試走取消及到期。**各建一張只有 1 件咖啡的台中訂單。第一張按「取消訂單」，第二張保持待付款滿 15 分鐘。到期後重新整理頁面、選台中門市，依編號打開詳情。兩張應分別顯示「已取消」與「已到期」，各釋放一次保留，咖啡實體庫存仍為 4。完成後登出。

在訂單區選擇商品，按「新增品項」並填寫數量，再按「建立訂單」。所有品項都有足夠可售量才成立。後端保存下單時的單價、數量與金額，並保留各品項庫存。提交結果不明時，在原分頁使用同一帳號及門市，按「確認同一筆訂單」以原鍵重送。若已關閉原分頁，先從訂單清單確認是否成立。

訂單成立後可用狀態、建立日期和單號篩選。日期依臺灣時間，起日包含、迄日不包含。點選單號可查看明細、狀態與庫存異動。待付款的訂單可取消，建立滿 15 分鐘也會釋放保留量，實體庫存維持原值。

在待付款的訂單詳情按「發起付款」，記下訂單編號。進入處理中後，訂單會保留庫存並顯示固定的付款識別。若連線中斷，先重新讀取原訂單。仍待付款時可再按「發起付款」。尚未確認建立的付款會由背景補辦，服務重啟後沿用原識別與剩餘次數。最多補辦三次。若顯示「自動補辦已達上限」，庫存仍會保留，可按「查詢付款結果」繼續處理同一筆付款。

總部面板的派送回應表示通知已排入。要確認是否結算，以訂單詳情的付款狀態、歷程及庫存異動為準。付款確認後，終局不可反轉。明確失敗後如要再購買，請建立新訂單。

`.local/demo/` 保存應用程式密鑰、訂單和模擬金流的資料庫密碼、通知簽章金鑰及初始帳密，請留在本機私密位置。建置及啟動只顯示帳密檔路徑。請一併備份私密設定與 PostgreSQL 資料。若只遺失通知金鑰，`up` 會產生新金鑰並讓兩端一起使用，待送通知仍沿用原事件。其他私密檔案遺失時，須從本機備份復原。

```sh
./bin/local demo ps
./bin/local demo restart
./bin/local demo down
```

`restart` 重啟網站、模擬金流、兩個排程器與 PostgreSQL，仍會處理先前排入的通知。`down` 停止並移除本案容器，保留 PostgreSQL volume。再次執行 `up` 會使用原有資料與帳密，適用於修改程式後重建。請一次執行一個啟動命令。若初始化中斷且 PostgreSQL 仍可啟動，使用原有設定重跑 `up` 可補齊缺少的角色及資料庫。角色權限或資料庫擁有者不符時，啟動會停止，請先檢查既有設定。

### 重設示範資料

```sh
./bin/local demo reset
```

先核對畫面上的工作目錄、Docker socket、Compose 專案、網站、資料庫及 volume，再輸入 `reset store-ordering`。直接按 Enter、關閉輸入或輸入其他文字會取消。測試模式使用 `./bin/local test reset`，確認文字是 `reset store-ordering-test`，清除的測試 volume 也包含 PHPUnit 資料庫。

重設會先停止並移除該模式的容器，再清除該模式的 PostgreSQL volume。沒有備份就無法還原訂單、庫存異動、付款歷程和待辦。完成後，網站會以初始商品和庫存重新啟動。從該模式的 `.local/<mode>/accounts.json` 取得新密碼並重新登入。舊 session 失效，新的簽章金鑰會拒絕使用舊金鑰送達的通知。

既有資料卷若尚未登記，請先用原工作目錄與原私密設定執行同模式的 `up`。初始化成功後會記錄歸屬。容器移除後，仍能透過工作目錄與私密設定指紋核對資料卷。

重設途中若失敗，請先閱讀錯誤。若新資料卷已建立但尚未登記，使用同模式的 `up` 補完初始化；其他重設失敗可重跑同模式的 `reset`。若程序被強制終止並留下 `/tmp/store-ordering.local.lock` 或 `/tmp/store-ordering-test.local.lock`，先確認原命令及其 Docker 子程序都已結束，再以 `rmdir` 移除該模式的鎖目錄。若要還原重設前的環境，須使用同一時間點的 volume 與私密設定備份。

## 架構與開發

瀏覽器從訂單應用載入 Vue 頁面並呼叫同源 API。訂單應用保存帳號、庫存、訂單、付款意圖及交易歷程。獨立的模擬金流服務保存付款結果、通知事件與派送待辦，以內部 HTTP 回應查詢及送出簽章通知。兩個服務各自使用 PostgreSQL 資料庫與服務帳號。通知與人工查詢共用結算路徑，在一個訂單資料庫交易內更新訂單、庫存與歷程。

- `backend/routes/web.php` 註冊同源 JSON API。登入與登出要帶 `GET /api/session` 回傳的 `csrfToken`，以 `X-CSRF-TOKEN` 送出。登入和登出回應會提供新的 token。
- 請求本文最多 64 KiB。PHP 原生解析的 multipart POST 需要可確認的 `Content-Length`。超限或長度無法確認時回傳 `413 PAYLOAD_TOO_LARGE`。
- Laravel 的 session guard 負責身分，查詢套用門市範圍，PostgreSQL 保障庫存資料約束。庫存資料以明確欄位回傳，後端逐次檢查門市存取權。資料庫 migration 與 PHP HTTP 測試位於 `backend/`。
- `POST /api/orders` 接受含 `storeId`、`operationKey` 和 `items` 的 JSON，明細包含 `productId` 與 `quantity`，最多 100 個不同商品。數值須為 JSON 正整數，最高 `9007199254740991`。同一使用者以相同鍵及內容重送會回原訂單。
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
- `frontend/src/` 是 Vue 單頁入口。Vite 建置時執行 TypeScript 型別檢查並產生 Laravel 讀取的資產 manifest。修改前端或後端後，用 `up` 重建對應映像。
- 展示與測試使用不同 Compose 專案、資料庫容器、volume、應用程式密鑰、通知簽章金鑰與 session cookie 名稱。各專案上限為 2 CPU、3 GiB，兩組同時啟動合計上限為 4 CPU、6 GiB。PostgreSQL 及模擬金流僅在各自的 Docker 網路內提供服務。訂單與模擬金流各自擁有資料庫與服務帳號，兩者透過 HTTP 交換付款資料。首次升級執行 `up` 會在各模式的 `.local/` 設定內補建 `payment-notifications.env`，並保留既有應用密鑰、帳密和資料。簽章金鑰只提供給模擬金流排程器和訂單網站。

## 測試手冊

先在初始庫存的隔離測試站執行自動化瀏覽器測試，再跑上面的展示路線。展示中的成功付款會實際扣減測試庫存。若打算再次使用需要初始庫存的腳本，請依重設章節核對並確認清除範圍。

```sh
./bin/local test up
./bin/local test phpunit
(cd frontend && npm ci && npm run typecheck && npm run build && npm run test:browser)
```

瀏覽器腳本會用掉同一來源的五次登入額度。腳本結束後等滿 60 秒，再從同一來源重跑、走展示路線或做 API 登入。若已收到 `429 LOGIN_THROTTLED`，可依回應的 `Retry-After` 秒數等候。

完成瀏覽器、HTTP 及重啟檢查後，執行 `./bin/local test down` 停止測試站並保留資料。

可先執行 `bash bin/test-reset-guards` 檢查確認與目標保護。該測試使用臨時設定及模擬的 Docker 指令。

測試網站為 `http://127.0.0.1:8011`，瀏覽器帳密由 `.local/test/accounts.json` 提供。PHPUnit 透過 Laravel HTTP 測試入口使用測試專案內的 `store_ordering_phpunit`。實際 HTTP 和瀏覽器使用同一測試專案內獨立的 `store_ordering_test`。

瀏覽器測試需要主機 Node.js 22.12 以上，預設使用 macOS 安裝的 Google Chrome。使用其他 Chrome 或 Chromium 時，可在執行 `npm run test:browser` 前設定 `CHROME_PATH`。如果測試在建單後中斷，先在隔離測試站查閱這次的測試訂單。待付款的訂單可取消或等候到期。若訂單已進入付款處理中，先用原訂單的「查詢付款結果」確認或補建同一筆付款。總部在「總部模擬付款」找到該筆付款，若仍待決定就按「決定失敗」。回原訂單再次查詢，確認付款失敗及保留釋放。核對可售量回到測試前的數量後，再重跑瀏覽器測試。

實際服務的安全檢查可先取得 `GET /api/session` 的 cookie 與 `csrfToken`，再用同一 cookie 呼叫 `POST /api/login`。省略或改錯 `X-CSRF-TOKEN` 時應收到 `419 CSRF_MISMATCH`。成功登入後，用回應的 `Set-Cookie` 更新 cookie jar，並使用回應本文的新 token。對 `POST /api/logout` 和合法內容的 `POST /api/orders` 分別省略或改錯 token，應回 419。以該登入 cookie 查詢 `GET /api/stores` 仍應成功，訂單清單與庫存也應維持原值。使用正確 token 登出後，重送登出前的登入 cookie 查詢門市應回 `401 UNAUTHENTICATED`。Chrome 可分別以三個帳號登入，核對可選門市與庫存數量。

驗收重設時，在隔離站記下既有訂單與庫存，先執行 `./bin/local test reset` 並直接按 Enter。確認命令取消後，查回原訂單、庫存及歷程。確定可以清除畫面列出的測試資料後，再重新執行重設並輸入正確的確認字串。重設完成後驗證舊 session 失效，以新初始密碼登入，兩店訂單清單應為空白，庫存應回到上表數量。

### 派送與重啟核對

僅在 8011 隔離站操作。執行下列指令後貼上要查的通知事件識別。第一列依序顯示排入數、已送達數、首次送達時間。第二列是訂單資料庫的成功收件數。對展示路線的新成功訂單，第一次派送後應為 1 次送達及 1 筆收件。再送一次後應為 2 次送達及 1 筆收件，詳情與庫存維持成功結算後的數值。

```sh
printf '通知事件識別\n'
IFS= read -r event_id
docker exec -i --user postgres store-ordering-test-db-1 psql -XAt -v ON_ERROR_STOP=1 \
  -d store_ordering_mock_payment_test -v "event_id=$event_id" <<'SQL'
SELECT count(*), count(delivered_at), min(delivered_at) AT TIME ZONE 'UTC'
FROM payment_notification_dispatches WHERE event_id = :'event_id'::uuid;
SQL
docker exec -i --user postgres store-ordering-test-db-1 psql -XAt -v ON_ERROR_STOP=1 \
  -d store_ordering_test -v "event_id=$event_id" <<'SQL'
SELECT count(*) FROM payment_notification_receipts
WHERE event_id = :'event_id'::uuid AND status = 200;
SQL
```

延遲案例在重啟前查同一事件，應有 1 筆排入、0 筆送達及 0 筆收件。重啟後用 `docker inspect store-ordering-test-mock-payment-scheduler-1 --format '{{.State.StartedAt}}'` 取得排程器啟動時間。通知送達後再查，應有 1 筆送達及 1 筆收件，首次送達的 UTC 時間須晚於排程器重啟的時間。若重啟前已送達，請用新的測試訂單重做延遲案例。

### 隔離站併發驗收

在 8011 登入總部，選台北門市。兩個情境都可用下面的門市列鎖確認請求確實重疊。先備妥要送出的操作，再開一個終端執行 `docker exec -it --user postgres store-ordering-test-db-1 psql -X -d store_ordering_test` 並輸入：

```sql
SET idle_in_transaction_session_timeout = '45s';
BEGIN;
SELECT id FROM stores WHERE id = 1 FOR UPDATE;
```

在另一個終端開同一個 `psql` 命令，輸入下面的查詢，並用 `\watch 0.2` 重複查看。觀察到兩筆等待後，立即在持鎖的終端執行 `ROLLBACK;` 釋放鎖。若操作失敗或中斷，也先執行 `ROLLBACK;`。

```sql
SELECT count(*) FROM pg_stat_activity
WHERE datname = current_database() AND usename = 'store_ordering'
  AND wait_event_type = 'Lock' AND query ILIKE '%from "stores"%'
  AND query ILIKE '%FOR NO KEY UPDATE%';
```

- **最後一件庫存。**用兩個獨立瀏覽器會話分別登入總部與台北店員，例如一般視窗與無痕視窗。先用一張待付款訂單把台北濾掛咖啡留到可售 1 件。取得門市鎖後，在兩個會話的開發者工具 Console 各執行下面的指令一次。程式會各自產生操作鍵。釋放鎖後應只得到一個 `201` 和一個 `409 INSUFFICIENT_STOCK`。台北可售變 0、實體庫存維持原值，台中數量不變。取消兩張成立的待付款訂單，確認台北可售量恢復。

  ```js
  const { csrfToken } = await (await fetch('/api/session')).json()
  const result = await fetch('/api/orders', {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    body: JSON.stringify({
      storeId: 1, operationKey: crypto.randomUUID(), items: [{ productId: 1, quantity: 1 }],
    }),
  })
  console.log(result.status, await result.json())
  ```

- **通知與人工查詢。**用新的台北訂單發起付款，總部決定失敗後先保留訂單詳情和付款列。取得鎖後，按詳情的「查詢付款結果」。觀察到一筆等待時，在同一頁的付款列按「立即送出／重送」。見到兩筆等待即釋放鎖，讓兩個請求繼續。依前節確認事件已送達，重新整理核對同一付款識別、唯一的失敗終局、一筆釋放及實體庫存不變。若未觀察到兩筆同時等待，請用新訂單重跑該情境。

### 負載驗收

主機需有 Python 3.10 以上。先停止 8011 測試環境並確認 `127.0.0.1:8012` 可用。每次用新的識別建立專用 Compose 專案、資料卷與帳密。

```sh
load_mode="load-$(openssl rand -hex 6)"
./bin/local "$load_mode" up
project="store-ordering-$load_mode"
evidence=".local/$load_mode"
{
    git rev-parse HEAD
    python3 --version
    docker version
    docker compose version
    docker exec "${project}-app-1" php --version
    docker exec "${project}-app-1" php artisan --version
    docker exec "${project}-db-1" postgres --version
    for active_project in "$project" store-ordering; do
        docker ps -q --filter "label=com.docker.compose.project=$active_project" |
        while IFS= read -r id; do
            docker inspect --format '{{.Name}} {{.Image}} {{.HostConfig.NanoCpus}} {{.HostConfig.Memory}}' "$id"
        done
    done
} > "$evidence/runtime.txt"
```

把主機型號、作業系統與記憶體也記入 `runtime.txt`。上面每個容器記錄映像、CPU 配額（十億分之一核心）及記憶體配額（位元組），核對本案合計不超過 4 CPU、6 GiB。先在另一個終端設為同一個 `load_mode`，開始記錄主機背景負載及五個容器的資源採樣。

```sh
project="store-ordering-$load_mode"
while :; do
    date -u
    uptime
    top -l 1 -n 0 | awk '/^(CPU usage|PhysMem|Load Avg)/'
    docker stats --no-stream "${project}-app-1" "${project}-db-1" "${project}-scheduler-1" \
        "${project}-mock-payment-1" "${project}-mock-payment-scheduler-1"
    sleep 5
done > ".local/$load_mode/resources.log"
```

回到原終端執行負載。腳本只接受空白的專用交易資料庫，建立兩店共 10,000 筆已結束的合成訂單及足量測試庫存。暖機 60 秒後，正式排定 300 秒、每秒 20 次的訂單入口請求。正式工作包含清單 1,800 次、詳情 1,200 次、庫存 600 次，以及建單、發起付款、決定結果、通知各 600 次。兩店各 300 組交易，其中各有 240 組成功、60 組明確失敗。

```sh
./bin/local "$load_mode" benchmark
```

負載結束後，用 Ctrl+C 停止另一個終端的採樣。在移除容器前保存模擬金流的存取日誌，即使量測未通過也保留。依 `benchmark/summary.json` 的 UTC 暖機及正式時窗，分別統計 `/api/payments` 與 `/api/payments/{id}/decision` 的實際次數，並記下其他衍生呼叫。

```sh
docker logs --timestamps "${project}-mock-payment-1" > "$evidence/mock-access.log" 2>&1
```

確認日誌命令成功，且已保存版本、配額、主機負載和容器採樣，再移除容器。採集失敗時先保留容器以便補錄。

```sh
./bin/local "$load_mode" down
```

產生器以測試金鑰簽署模擬金流實際保存的事件本文，按排程送至訂單通知入口，量測驗簽及結算。金流的派送佇列、輪詢、重送、故障與競爭由獨立測試驗證。發起付款與決定結果引發的內部 HTTP 會佔用服務資源，其耗時也包含在入口量測內。

`.local/$load_mode/benchmark/` 保存逐筆請求、未送出原因、兩個資料庫的一致性核對及摘要。腳本的通過訊息涵蓋正式流量與配比、交易完整性、讀取 p95 ≤500 毫秒、交易操作 p95 ≤1 秒、非預期錯誤率 <1% 和正確性錯誤為 0。完整驗收另須核對實際內部 HTTP、版本、配額與資源採樣。量測中斷或未通過時保留證據，重新驗收請使用新識別。`down` 保留資料卷與私密設定，供測後檢查。

#### 本機實測摘要

2026-11-07 在 Apple M3、24 GiB RAM、macOS 27.0.1 上，以 Docker Engine 29.8.2、Compose 5.5.0、PHP 8.5.12、Laravel 13.40.0 與 PostgreSQL 18.6 執行。本案容器合計配額為 4 CPU、6 GiB。起始有 10,000 筆歷史訂單，暖機 60 秒，正式量測 300 秒。

| 項目 | 正式量測 | 驗收門檻 |
| --- | ---: | ---: |
| 入口請求 | 6,000（每秒 20 次） | 6,000（每秒 20 次） |
| 讀取 p95 | 27.07 毫秒 | ≤500 毫秒 |
| 交易操作 p95 | 36.71 毫秒 | ≤1 秒 |
| 未送出的排定請求 | 0 | 0 |
| 非預期錯誤 | 0（0%） | <1% |
| 交易正確性錯誤 | 0 | 0 |

兩店各完成 300 組交易，其中各 240 組成功、60 組明確失敗。另觀察到 1,200 次衍生的內部 HTTP（建立付款 600 次、決定結果 600 次）。測後核對含暖機共 720 筆新增訂單的付款、庫存及歷程，結果一致。交易 p95 衡量單次入口操作。回呼使用模擬金流保存的事件本文送到實際驗簽與結算端點，派送佇列的輪詢與等待時間由獨立測試涵蓋。

正式量測期間，每個負載容器採樣 32 次。主機的 1 分鐘平均負載介於 2.10～3.64，CPU 閒置率介於 61.50%～75.57%。訂單應用的抽樣峰值為單核心的 25.42% 與 81.64 MiB 記憶體，PostgreSQL 為 18.62% 與 65.17 MiB。
