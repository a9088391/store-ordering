# 門市訂單系統

面試用 side project，展示總部與門市人員如何查看庫存、建立多品項訂單、保留庫存，以及透過模擬金流完成付款。總部可切換兩店，店員使用本店資料。可售庫存是實體庫存扣除有效保留後的數量。

技術採 Laravel 13、PHP 8.5、Vue 3、TypeScript、Vite 與 PostgreSQL 18。Laravel 提供訂單 API 與前端靜態檔，瀏覽器透過同一個來源登入及操作。獨立的模擬金流服務透過內部 HTTP 與訂單應用通訊。這個版本的規格拆分、實作與驗證採 AI 協作，依 API、真實服務及瀏覽器各自檢查對應行為。

## 本機啟動

需有使用本機 Unix socket 的 Docker Engine、Docker Compose、`openssl`，以及 Chrome 或 Chromium。首次建置需連線取得官方容器映像及鎖檔中的套件。預設展示埠為 `127.0.0.1:8010`，請先確認該埠可用。

```sh
./bin/local demo up
```

在本機開啟 [門市訂單系統](http://127.0.0.1:8010)。用本機編輯器開啟 `.local/demo/accounts.json` 取得隨機初始密碼。三個帳號為：

| 帳號 | 可查閱門市 |
| --- | --- |
| `hq@example.test` | 台北門市、台中門市 |
| `taipei@example.test` | 台北門市 |
| `taichung@example.test` | 台中門市 |

登入後可在「門市庫存」查閱濾掛咖啡與燕麥餅乾。台中門市的燕麥餅乾庫存為 0，可用來確認門市範圍和零庫存顯示。使用頁首「登出」結束 session。

在訂單區選擇商品，按「新增品項」並填寫數量，再按「建立訂單」。所有品項都有足夠可售量才成立。後端保存下單時的單價、數量與金額，並保留各品項庫存。提交結果不明時，在原分頁使用同一帳號及門市，按「確認同一筆訂單」以原鍵重送。若已關閉原分頁，先從訂單清單確認是否成立。

訂單成立後可用狀態、建立日期和單號篩選。日期依臺灣時間，起日包含、迄日不包含。點選單號可查看明細、狀態與庫存異動。待付款的訂單可取消，建立滿 15 分鐘也會釋放保留量，實體庫存維持原值。

在待付款的訂單詳情按「發起付款」，記下訂單編號。進入處理中後，訂單會保留庫存並顯示固定的付款識別。若連線中斷，先重新讀取原訂單。仍待付款時可再按「發起付款」。尚未確認建立的付款會由背景補辦，服務重啟後沿用原識別與剩餘次數。最多補辦三次。若顯示「自動補辦已達上限」，庫存仍會保留，可按「查詢付款結果」繼續處理同一筆付款。

總部登入後，在「總部模擬付款」面板按「重新載入付款」，依訂單編號找出該筆付款，再按「決定成功」或「決定失敗」。回到原訂單，按「查詢付款結果」同步。成功會扣除實體庫存並解除保留，失敗只解除保留。訂單詳情會顯示付款狀態、歷程及庫存異動；結果待確認時，庫存會繼續保留。

`.local/demo/` 保存應用程式密鑰、訂單和模擬金流的資料庫密碼及初始帳密，請留在本機私密位置。建置及啟動只顯示帳密檔路徑。請一併備份私密設定與 PostgreSQL 資料；檔案遺失時，需從本機備份復原。

```sh
./bin/local demo ps
./bin/local demo restart
./bin/local demo down
```

`restart` 重啟網站、模擬金流、訂單排程器與 PostgreSQL。`down` 停止並移除本案容器，保留 PostgreSQL volume。再次執行 `up` 會使用原有資料與帳密，適用於修改程式後重建。請一次執行一個啟動命令。若初始化中斷且 PostgreSQL 仍可啟動，使用原有設定重跑 `up` 可補齊缺少的角色及資料庫。角色權限或資料庫擁有者不符時，啟動會停止，請先檢查既有設定。

## 開發與驗證

- `backend/routes/web.php` 註冊同源 JSON API。登入與登出要帶 `GET /api/session` 回傳的 `csrfToken`，以 `X-CSRF-TOKEN` 送出。登入和登出回應會提供新的 token。
- 請求本文最多 64 KiB。PHP 原生解析的 multipart POST 需要可確認的 `Content-Length`。超限或長度無法確認時回傳 `413 PAYLOAD_TOO_LARGE`。
- Laravel 的 session guard 負責身分，查詢套用門市範圍，PostgreSQL 保障庫存資料約束。庫存資料以明確欄位回傳，後端逐次檢查門市存取權。資料庫 migration 與 PHP HTTP 測試位於 `backend/`。
- `POST /api/orders` 接受含 `storeId`、`operationKey` 和 `items` 的 JSON，明細包含 `productId` 與 `quantity`，最多 100 個不同商品。數值須為 JSON 正整數，最高 `9007199254740991`。同一使用者以相同鍵及內容重送會回原訂單。
- `GET /api/orders` 可用 `storeId`、`status`、`orderNumber`、`from`、`to`、`page` 和 `perPage` 查詢。`GET /api/orders/{id}` 回傳單筆詳情。`POST /api/orders/{id}/cancel` 取消有效的待付款訂單。所有瀏覽器 POST 都須帶 CSRF token。
- `POST /api/orders/{id}/payment` 發起付款，`POST /api/orders/{id}/payment/query` 查詢並同步同一付款。訂單應用先保存意圖與固定識別，再呼叫模擬金流，每次外呼最長 3 秒。逾時、查無付款或服務無法連線時，可用人工查詢恢復。
- 訂單排程器每分鐘執行 `store-ordering:recover-payments`，以原付款識別補送尚未確認的建立請求。PostgreSQL 在送出前記下已用次數，程序在此時中斷也會占用一次。同一付款最多領取三次額度，每次領取至少間隔一分鐘。確認已建立後會停止補辦，後續結果可用人工查詢同步。
- 總部以 `GET /api/mock-payments` 查詢模擬付款，以 `POST /api/mock-payments/{paymentId}/decision` 提交 `{"status":"paid"}` 或 `{"status":"failed"}`。模擬結果一旦保存即固定，訂單應用會在人工查詢或背景補辦取得同一付款的明確結果時更新狀態。
- 到期排程每分鐘檢查待付款訂單。庫存查詢與新訂單會依資料庫時間即時處理該店的到期保留；逾期的同鍵重送會先釋放該單並回傳已到期詳情。建單、取消、到期、付款啟動及結果套用使用同一門市的交易鎖，同步訂單、庫存與歷程。處理中的付款繼續保留庫存。
- Session 閒置 30 分鐘或登入滿 8 小時後失效。每 60 秒，同一帳號及同一連線來源各可嘗試登入 5 次。超限時回傳 `429 LOGIN_THROTTLED` 和 `Retry-After`。
- `frontend/src/` 是 Vue 單頁入口。Vite 建置時執行 TypeScript 型別檢查並產生 Laravel 讀取的資產 manifest。修改前端或後端後，用 `up` 重建對應映像。
- 展示與測試使用不同 Compose 專案、資料庫容器、volume、應用程式密鑰與 session cookie 名稱。各專案上限為 2 CPU、3 GiB，兩組同時啟動合計上限為 4 CPU、6 GiB。PostgreSQL 及模擬金流僅在各自的 Docker 網路內提供服務。訂單與模擬金流各自擁有資料庫與服務帳號，兩者透過 HTTP 交換付款資料。首次升級執行 `up` 會建立模式專用的 `mock-payment.env`，並保留既有應用密鑰、帳密和資料。

```sh
./bin/local test up
./bin/local test phpunit
(cd frontend && npm ci && npm run test:browser)
./bin/local test down
```

測試網站為 `http://127.0.0.1:8011`，瀏覽器帳密由 `.local/test/accounts.json` 提供。PHPUnit 透過 Laravel HTTP 測試入口使用測試專案內的 `store_ordering_phpunit`。實際 HTTP 和瀏覽器使用同一測試專案內獨立的 `store_ordering_test`。

瀏覽器測試需要主機 Node.js 22.12 以上，預設使用 macOS 安裝的 Google Chrome。使用其他 Chrome 或 Chromium 時，可在執行 `npm run test:browser` 前設定 `CHROME_PATH`。瀏覽器流程會送出五次登入，相同來源重跑前請等上一個 60 秒限流時窗結束。如果測試在建單後中斷，先在隔離測試站查閱這次的測試訂單。待付款的訂單可取消或等候到期。若訂單已進入付款處理中，先用原訂單的「查詢付款結果」確認或補建同一筆付款。總部在「總部模擬付款」找到該筆付款，若仍待決定就按「決定失敗」。回原訂單再次查詢，確認付款失敗及保留釋放。核對可售量回到測試前的數量後，再重跑瀏覽器測試。

實際服務的安全檢查可先取得 `GET /api/session` 的 cookie 與 `csrfToken`，再用同一 cookie 呼叫 `POST /api/login`。省略或改錯 `X-CSRF-TOKEN` 時應收到 `419 CSRF_MISMATCH`。正確登入後，對 `POST /api/logout` 分別省略或改錯 token，兩次都應回 419，且原 cookie 仍可查詢 `GET /api/stores`。使用正確 token 登出後，重送舊 cookie 查詢門市應回 `401 UNAUTHENTICATED`。Chrome 可分別以三個帳號登入，核對可選門市與庫存數量。
