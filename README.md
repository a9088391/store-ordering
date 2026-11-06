# 門市訂單系統

面試用 side project，展示總部與門市人員登入後，如何查閱授權門市的商品、TWD 單價、實體庫存與可售庫存。總部可切換兩店，店員只會收到本店的資料。這個版本的可售庫存等於實體庫存。

技術採 Laravel 13、PHP 8.5、Vue 3、TypeScript、Vite 與 PostgreSQL 18。Laravel 提供 API 與前端靜態檔，瀏覽器透過同一個來源登入及讀取資料。這個版本的規格拆分、實作與驗證採 AI 協作，依 API、真實服務及瀏覽器各自檢查對應行為。

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

`.local/demo/` 保存應用程式密鑰、資料庫密碼及初始帳密，請留在本機私密位置。建置及啟動只顯示帳密檔路徑。請一併備份私密設定與 PostgreSQL 資料；檔案遺失時，需從本機備份復原。

```sh
./bin/local demo ps
./bin/local demo restart
./bin/local demo down
```

`restart` 重啟既有服務。`down` 停止並移除本案容器，保留 PostgreSQL volume。再次執行 `up` 會使用原有資料與帳密，適用於修改程式後重建。請一次執行一個啟動命令。若初始化中斷且 PostgreSQL 仍可啟動，使用原有設定重跑 `up` 可補齊缺少的角色及資料庫。角色權限或資料庫擁有者不符時，啟動會停止，請先檢查既有設定。

## 開發與驗證

- `backend/routes/web.php` 註冊同源 JSON API。登入與登出要帶 `GET /api/session` 回傳的 `csrfToken`，以 `X-CSRF-TOKEN` 送出。登入和登出回應會提供新的 token。
- 請求本文最多 64 KiB。PHP 原生解析的 multipart POST 需要可確認的 `Content-Length`。超限或長度無法確認時回傳 `413 PAYLOAD_TOO_LARGE`。
- Laravel 的 session guard 負責身分，查詢套用門市範圍，PostgreSQL 保障庫存資料約束。庫存資料以明確欄位回傳，後端逐次檢查門市存取權。資料庫 migration 與 PHP HTTP 測試位於 `backend/`。
- Session 閒置 30 分鐘或登入滿 8 小時後失效。每 60 秒，同一帳號及同一連線來源各可嘗試登入 5 次。超限時回傳 `429 LOGIN_THROTTLED` 和 `Retry-After`。
- `frontend/src/` 是 Vue 單頁入口。Vite 建置時執行 TypeScript 型別檢查並產生 Laravel 讀取的資產 manifest。修改前端或後端後，用 `up` 重建對應映像。
- 展示與測試使用不同 Compose 專案、資料庫容器、volume、應用程式密鑰與 session cookie 名稱。各專案上限為 2 CPU、3 GiB，兩組同時啟動合計上限為 4 CPU、6 GiB。PostgreSQL 連接埠只在各自的 Docker 網路內使用。

```sh
./bin/local test up
./bin/local test phpunit
(cd frontend && npm ci && npm run test:browser)
./bin/local test down
```

測試網站為 `http://127.0.0.1:8011`，瀏覽器帳密由 `.local/test/accounts.json` 提供。PHPUnit 透過 Laravel HTTP 測試入口使用測試專案內的 `store_ordering_phpunit`。實際 HTTP 和瀏覽器使用同一測試專案內獨立的 `store_ordering_test`。

瀏覽器測試需要主機 Node.js 22.12 以上，預設使用 macOS 安裝的 Google Chrome。使用其他 Chrome 或 Chromium 時，可在執行 `npm run test:browser` 前設定 `CHROME_PATH`。瀏覽器流程會送出四次登入，相同來源重跑前請等上一個 60 秒限流時窗結束。

實際服務的安全檢查可先取得 `GET /api/session` 的 cookie 與 `csrfToken`，再用同一 cookie 呼叫 `POST /api/login`。省略或改錯 `X-CSRF-TOKEN` 時應收到 `419 CSRF_MISMATCH`。正確登入後，對 `POST /api/logout` 分別省略或改錯 token，兩次都應回 419，且原 cookie 仍可查詢 `GET /api/stores`。使用正確 token 登出後，重送舊 cookie 查詢門市應回 `401 UNAUTHENTICATED`。Chrome 可分別以三個帳號登入，核對可選門市與庫存數量。
