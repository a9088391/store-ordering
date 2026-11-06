import assert from 'node:assert/strict'
import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { chromium } from 'playwright-core'

const origin = 'http://127.0.0.1:8011'
const testCookie = 'store_ordering_test_session'
const chrome = process.env.CHROME_PATH
  ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'

// 從原生標籤填寫登入表單；提交一次後由各情境確認畫面結果。
async function submitLogin(page, email, password) {
  await page.getByLabel('帳號').fill(email)
  await page.getByLabel('密碼').fill(password)
  await page.getByRole('button', { name: '登入', exact: true }).click()
}

// 等待所選門市的庫存到達，再核對兩個品項、單價與全部庫存欄位。
async function expectInventory(page, coffee, cookies) {
  const table = page.getByRole('table', { name: '門市庫存明細' })
  const coffeeRow = table
    .getByRole('row')
    .filter({ has: page.getByRole('rowheader', { name: '濾掛咖啡', exact: true }) })
  await coffeeRow
    .getByRole('cell', { name: String(coffee), exact: true })
    .first()
    .waitFor()

  // 逐欄比對內容，避免只因其中一欄的數字正確就通過。
  assert.deepEqual(await table.getByRole('columnheader').allTextContents(), [
    '商品', '單價', '實體庫存', '可售庫存',
  ])
  const rows = await table.locator('tbody tr').evaluateAll(
    // 從原生列取出獨立儲存格，保留欄位順序。
    (entries) => entries.map((row) => Array.from(row.cells, (cell) => cell.textContent)),
  )
  assert.deepEqual(rows, [
    ['濾掛咖啡', '45 元', String(coffee), String(coffee)],
    ['燕麥餅乾', '60 元', String(cookies), String(cookies)],
  ])
}

// 比對保留或釋放後的實存量與可售量，確認建單不直接扣除實存。
async function expectInventoryBalances(page, coffeeOnHand, coffeeAvailable, cookiesOnHand, cookiesAvailable) {
  const table = page.getByRole('table', { name: '門市庫存明細' })

  // 等待兩個商品指定的可售量都更新後，再讀取整張庫存表。
  for (const [name, available] of [
    ['濾掛咖啡', coffeeAvailable],
    ['燕麥餅乾', cookiesAvailable],
  ]) {
    // 只看可售欄，避免實存量相同時誤判為畫面已回讀。
    await page.waitForFunction(({ productName, expected }) => {
      const row = Array.from(document.querySelectorAll('table[aria-label="門市庫存明細"] tbody tr'))
        .find((entry) => entry.cells[0].textContent === productName)
      return row?.cells[3].textContent === expected
    }, { productName: name, expected: String(available) })
  }

  const rows = await table.locator('tbody tr').evaluateAll(
    // 從列標題識別商品，讀取單價後的實存量及可售量。
    (entries) => entries.map((row) => [row.cells[0].textContent, row.cells[2].textContent, row.cells[3].textContent]),
  )
  assert.deepEqual(rows, [
    ['濾掛咖啡', String(coffeeOnHand), String(coffeeAvailable)],
    ['燕麥餅乾', String(cookiesOnHand), String(cookiesAvailable)],
  ])
}

// 確認桌機、手機及斷點兩側都沒有水平溢位，資料也留在各自欄位內。
async function expectReadableTable(page) {
  for (const width of [320, 390, 480, 481, 1280]) {
    await page.setViewportSize({ width, height: 800 })

    // 讀取實際生效的尺寸與每個表格欄位的內容寬度。
    const measured = await page.evaluate(() => ({
      width: window.innerWidth,
      content: document.documentElement.scrollWidth,
      // 逐欄檢查，避免整頁沒有溢位但表格文字仍蓋到相鄰欄位。
      cellsFit: Array.from(document.querySelectorAll('th, td')).every(
        (cell) => cell.scrollWidth <= cell.clientWidth,
      ),
    }))
    assert.equal(measured.width, width)
    assert.equal(measured.content, width)
    assert.equal(measured.cellsFit, true)
  }
}

// 在手機與桌機寬度核對訂單面板及原生控制項都留在可視範圍。
async function expectOrderLayout(page) {
  for (const width of [320, 1280]) {
    await page.setViewportSize({ width, height: 900 })

    // 檢查整頁、面板及每個表單控制項的實際位置。
    const measured = await page.evaluate(() => {
      const panel = document.querySelector('.order-panel')
      // 以元素邊界統一檢查面板與控制項是否留在視窗內。
      const bounds = (element) => {
        const rect = element.getBoundingClientRect()
        return { left: rect.left, right: rect.right }
      }
      return {
        width: window.innerWidth,
        content: document.documentElement.scrollWidth,
        panel: bounds(panel),
        controls: Array.from(panel.querySelectorAll('input, select, button'), bounds),
      }
    })
    assert.equal(measured.width, width)
    assert.equal(measured.content, width)
    assert.ok(measured.panel.left >= 0 && measured.panel.right <= width)
    assert.equal(measured.controls.every(({ left, right }) => left >= 0 && right <= width), true)
  }
}

// 確認 HQ 長付款識別與終局派送控制在手機及桌機面板內可讀、可操作。
async function expectPaymentPanelLayout(page, paymentId) {
  const panel = page.getByRole('region', { name: '總部模擬付款' })
  for (const width of [320, 1280]) {
    await page.setViewportSize({ width, height: 900 })

    // 檢查面板、識別文字與操作按鈕的實際邊界及文字換行。
    const measured = await panel.evaluate((element, expectedId) => {
      const bounds = (node) => {
        const rect = node.getBoundingClientRect()
        return { left: rect.left, right: rect.right }
      }
      const identifier = Array.from(element.querySelectorAll('.payment-id'))
        .find((node) => node.textContent === expectedId)
      return {
        width: window.innerWidth,
        content: document.documentElement.scrollWidth,
        panel: bounds(element),
        identifierFits: identifier?.scrollWidth <= identifier?.clientWidth,
        controls: Array.from(element.querySelectorAll('button'), bounds),
      }
    }, paymentId)
    assert.equal(measured.width, width)
    assert.equal(measured.content, width)
    assert.ok(measured.panel.left >= 0 && measured.panel.right <= width)
    assert.equal(measured.identifierFits, true)
    assert.equal(measured.controls.every(({ left, right }) => left >= 0 && right <= width), true)
  }
}

// 以同一登入會話輪詢唯讀訂單詳情，直到背景通知套用到終局。
async function waitForOrderStatus(context, orderId, status) {
  const deadline = Date.now() + 8000
  let order
  while (Date.now() < deadline) {
    // 每次只讀訂單詳情，確認背景通知是否已更新狀態。
    const response = await context.request.get(`/api/orders/${orderId}`, {
      headers: { Accept: 'application/json' },
      maxRedirects: 0,
    })
    assert.equal(response.status(), 200)
    order = (await response.json()).data
    if (order.status === status) return order
    // 等待目標處理中的訂單，不接受其他提早終局。
    assert.equal(order.status, 'processing')
    // 以短間隔輪詢已提交的背景工作，不以固定延遲推定完成。
    await new Promise((resolve) => setTimeout(resolve, 250))
  }
  assert.equal(order?.status, status)
  return order
}

// 驗證登出後的登入表單可用，且重載不會再顯示上一位使用者的庫存。
async function expectLogout(page) {
  await page.getByRole('button', { name: '登出' }).click()
  await page.getByRole('heading', { name: '登入', exact: true }).waitFor()
  assert.equal(await page.getByRole('table').count(), 0)
  await page.reload()
  await page.getByRole('heading', { name: '登入', exact: true }).waitFor()
  assert.equal(await page.getByRole('table').count(), 0)
}

// 保留實際付款操作及完整回應，只置換付款識別以驗證前端拒收。
async function fulfillPaymentIdentity(route, identity) {
  const response = await route.fetch()
  assert.equal(response.status(), 200)
  const payload = await response.json()
  payload.data.payment.id = identity
  await route.fulfill({ status: response.status(), json: payload })
}

let phase = '啟動本機 Chrome'
let browser
const fixturePasswords = []
const orderRequestBodies = []
let orderPostCount = 0
let committedOrderId
let trackedOrderKey
let paymentOrderId
let replacementOrderId
let dispatchOrderId
let paymentId
let dispatchPaymentId
let paymentStartPostCount = 0
let paymentQueryPostCount = 0

try {
  browser = await chromium.launch({ executablePath: chrome })
  const context = await browser.newContext({ baseURL: origin })
  const page = await context.newPage()
  page.setDefaultTimeout(8000)
  const pageErrors = []
  // 收集未捕捉的頁面例外；刻意觸發的 API 拒絕仍由畫面斷言驗證。
  page.on('pageerror', (error) => pageErrors.push(error.message))

  // 固定讀取本機測試站；收到正確的測試 cookie 前不讀取或提交任何帳密。
  phase = '確認 8011 測試站與隔離 cookie'
  const response = await context.request.get('/api/session', {
    headers: { Accept: 'application/json' },
    maxRedirects: 0,
  })
  assert.equal(response.ok(), true)
  assert.equal(response.url(), `${origin}/api/session`)
  // 核對測試 cookie 的實際名稱，避免把帳密送到展示站。
  assert.equal((await context.cookies(origin)).some((cookie) => cookie.name === testCookie), true)

  phase = '取得本機測試帳密'
  let accounts
  try {
    accounts = JSON.parse(await readFile(new URL('../../.local/test/accounts.json', import.meta.url), 'utf8'))
  } catch {
    // JSON 解析錯誤可能包含原始本文，只保留安全診斷。
    throw new Error('缺少測試帳密檔，或檔案不是有效的 JSON。')
  }
  // 先確認三組測試帳密都存在，以免中途浪費登入額度。
  const emails = ['hq@example.test', 'taipei@example.test', 'taichung@example.test']
  for (const email of emails) {
    assert.equal(typeof accounts[email], 'string')
    assert.notEqual(accounts[email].length, 0)
    fixturePasswords.push(accounts[email])
  }

  // 只在測試站同來源的實際頁面輸入憑證；先檢查標題、登入欄位和鍵盤焦點。
  phase = '驗證登入畫面與鍵盤焦點'
  await page.goto('/')
  assert.equal(page.url(), `${origin}/`)
  await page.getByRole('heading', { name: '門市訂單系統' }).waitFor()
  await page.getByRole('heading', { name: '登入', exact: true }).waitFor()
  await page.keyboard.press('Tab')
  // 由 DOM 檢查焦點是否走到第一個欄位，並套用可見的焦點樣式。
  const focused = await page.getByLabel('帳號').evaluate(
    (input) => input.matches(':focus-visible') && getComputedStyle(input).outlineStyle === 'solid',
  )
  assert.equal(focused, true)

  // 先驗證密碼錯誤後可直接修正，且原帳號保留、錯誤密碼已清除。
  phase = '驗證錯誤登入與修正'
  await submitLogin(page, emails[0], 'browser-test-wrong-password')
  await page
    .getByRole('status')
    .filter({ hasText: '帳號或密碼不正確。' })
    .waitFor()
  assert.equal(await page.getByLabel('帳號').inputValue(), emails[0])
  assert.equal(await page.getByLabel('密碼').inputValue(), '')
  await submitLogin(page, emails[0], accounts[emails[0]])

  // 總部可查詢兩店，包含零庫存，重載後仍可查詢；登出則清掉庫存畫面。
  phase = '驗證總部門市選擇、庫存、畫面寬度與重載'
  await page.getByRole('heading', { name: '門市庫存', exact: true }).waitFor()
  const store = page.getByLabel('門市', { exact: true })
  await store.waitFor()
  assert.deepEqual(await store.getByRole('option').allTextContents(), ['台北門市', '台中門市'])
  await expectInventory(page, 12, 3)
  await store.selectOption({ label: '台中門市' })
  await expectInventory(page, 4, 0)
  await expectReadableTable(page)
  await page.reload()
  await expectInventory(page, 12, 3)

  // 總部建立多品項訂單；真實 API 已提交後刻意丟棄瀏覽器收到的回應。
  phase = '驗證訂單建立即使回應遺失仍可恢復同一筆'
  await page.getByRole('heading', { name: '建立訂單', exact: true }).waitFor()
  await page.getByLabel('商品', { exact: true }).selectOption({ label: '濾掛咖啡' })
  await page.getByRole('button', { name: '新增品項', exact: true }).click()
  await page.getByLabel('商品', { exact: true }).selectOption({ label: '燕麥餅乾' })
  await page.getByRole('button', { name: '新增品項', exact: true }).click()
  await page.getByLabel('數量（濾掛咖啡）', { exact: true }).fill('2')
  await page.getByLabel('數量（燕麥餅乾）', { exact: true }).fill('1')

  // 留下每次實際送出的本文，以逐字確認恢復時沒有換鍵或改內容。
  page.on('request', (request) => {
    if (request.url() === `${origin}/api/orders` && request.method() === 'POST') {
      const body = request.postData()
      const operationKey = JSON.parse(body).operationKey
      if (trackedOrderKey === undefined || operationKey === trackedOrderKey) orderRequestBodies.push(body)
    }
  })
  // 第一個 POST 由真實 API 執行並回傳 201，測試只丟棄送回頁面的回應。
  const interceptOriginalOrderPosts = async (route) => {
    if (route.request().method() !== 'POST') return route.continue()
    orderPostCount++
    if (orderPostCount === 1) {
      const committed = await route.fetch()
      assert.equal(committed.status(), 201)
      const created = (await committed.json()).data
      assert.equal(Number.isSafeInteger(created.id) && created.id > 0, true)
      committedOrderId = created.id
      assert.equal(created.storeId, 1)
      assert.equal(created.status, 'awaiting_payment')
      assert.equal(created.total, 150)
      assert.deepEqual(created.items.map(({ name, quantity, unitPrice, subtotal }) => ({
        name, quantity, unitPrice, subtotal,
      })), [
        { name: '濾掛咖啡', quantity: 2, unitPrice: 45, subtotal: 90 },
        { name: '燕麥餅乾', quantity: 1, unitPrice: 60, subtotal: 60 },
      ])
      await route.abort()
      return
    }
    if (orderPostCount === 2) {
      // 明確確認後模擬一次 CSRF 失效；不把測試回應當成後端安全驗收。
      await route.fulfill({
        status: 419,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'CSRF token mismatch.' }),
      })
      return
    }
    if (orderPostCount === 3) {
      // 以真實成功回應為底改成空異動清單，確認缺少明細時不會誤判為完成。
      const committed = await route.fetch()
      assert.equal(committed.status(), 200)
      const payload = await committed.json()
      assert.equal(payload.data.id, committedOrderId)
      assert.equal(payload.data.stockLedger.length, 2)
      const emptyLedgerOrder = { ...payload.data, stockLedger: [] }
      await route.fulfill({
        status: committed.status(),
        contentType: 'application/json',
        body: JSON.stringify({ ...payload, data: emptyLedgerOrder }),
      })
      return
    }
    await route.abort()
  }
  await page.route(`${origin}/api/orders`, interceptOriginalOrderPosts)

  await page.getByRole('button', { name: '建立訂單', exact: true }).click()
  await page.getByRole('status').filter({
    hasText: '建立結果尚未確認。請使用原內容確認同一筆訂單。',
  }).waitFor()
  const firstBody = orderRequestBodies[0]
  assert.equal(typeof firstBody, 'string')
  const firstDraft = JSON.parse(firstBody)
  trackedOrderKey = firstDraft.operationKey
  assert.equal(firstDraft.storeId, 1)
  assert.equal(typeof firstDraft.operationKey, 'string')
  assert.notEqual(firstDraft.operationKey, '')
  assert.deepEqual(firstDraft.items.map(({ quantity }) => quantity), [2, 1])
  assert.equal(new Set(firstDraft.items.map(({ productId }) => productId)).size, 2)
  // 後續重載及身分切換也會核對沒有自動重送。
  assert.equal(orderPostCount, 1)

  // 同帳號重載會恢復確認按鈕，但不會自行再寫入。
  await page.reload()
  await page.getByRole('button', { name: '確認同一筆訂單', exact: true }).waitFor()
  await expectInventoryBalances(page, 12, 10, 3, 2)
  assert.equal(orderPostCount, 1)

  phase = '驗證總部登出與重載'
  await expectLogout(page)

  // 兩店店員都沒有門市切換控制，且只能讀取自己門市的庫存。
  for (const [email, name, coffee, cookies] of [
    [emails[1], '台北門市', 12, 3],
    [emails[2], '台中門市', 4, 0],
  ]) {
    phase = `驗證${name}店員登入、本店庫存與登出`
    await submitLogin(page, email, accounts[email])
    await page.getByText(`門市：${name}`, { exact: true }).waitFor()
    assert.equal(await page.getByLabel('門市', { exact: true }).count(), 0)
    if (name === '台北門市') {
      // 其他登入身分看得到共享庫存異動，但不顯示總部的待確認內容。
      await expectInventoryBalances(page, 12, 10, 3, 2)
      assert.equal(await page.getByRole('button', { name: '確認同一筆訂單', exact: true }).count(), 0)
      assert.equal(await page.getByRole('button', { name: '建立訂單', exact: true }).isEnabled(), false)
      assert.equal(await page.getByRole('heading', { name: '總部模擬付款', exact: true }).count(), 0)
      assert.equal(orderPostCount, 1)

      // 店員用本店可見庫存建單並發起付款，測試丟棄回應但不重送 POST。
      await page.unroute(`${origin}/api/orders`)
      await page.getByLabel('商品', { exact: true }).selectOption({ label: '濾掛咖啡' })
      await page.getByRole('button', { name: '新增品項', exact: true }).click()
      await page.getByLabel('數量（濾掛咖啡）', { exact: true }).fill('1')
      await page.getByRole('button', { name: '建立訂單', exact: true }).click()
      await page.getByRole('status').filter({ hasText: /^已確認訂單 #[0-9]+。$/ }).waitFor()
      const paymentOrderHeading = page.locator('#order-detail-heading')
      paymentOrderId = Number((await paymentOrderHeading.innerText()).replace('訂單 #', ''))
      assert.equal(Number.isSafeInteger(paymentOrderId) && paymentOrderId > 0, true)
      const paymentDetailPath = `/api/orders/${paymentOrderId}`
      const paymentStartPath = `${origin}${paymentDetailPath}/payment`

      // 等建單的讀取穩定，再記錄付款安全回讀與庫存、清單刷新順序。
      phase = '驗證發起付款回應遺失後先完成詳情回讀'
      await expectInventoryBalances(page, 12, 9, 3, 2)
      await page.getByRole('button', { name: `查看訂單 #${paymentOrderId}`, exact: true }).waitFor()
      const refreshPaths = ['/api/orders', '/api/stores/1/inventory']
      const refreshSequence = []
      // 記錄其他讀取發出的時點，以及安全回讀收到回應的時點。
      const recordPaymentRefresh = (request) => {
        const path = new URL(request.url()).pathname
        if (request.method() === 'GET' && refreshPaths.includes(path)) refreshSequence.push(path)
      }
      const recordPaymentReadback = (response) => {
        if (response.url() === `${origin}${paymentDetailPath}`) refreshSequence.push(paymentDetailPath)
      }
      page.on('request', recordPaymentRefresh)
      page.on('response', recordPaymentReadback)

      // 記錄店員實際送出的次數，防止未知回應造成背景重送。
      page.on('request', (request) => {
        if (request.url() === paymentStartPath && request.method() === 'POST') paymentStartPostCount++
      })
      // 先由真實 API 建立固定付款，再丟棄回應以走到前端安全回讀。
      await page.route(paymentStartPath, async (route) => {
        if (route.request().method() !== 'POST') return route.continue()
        const committed = await route.fetch()
        assert.equal(committed.status(), 200)
        const created = (await committed.json()).data
        paymentId = created.payment.id
        assert.equal(created.id, paymentOrderId)
        assert.equal(created.storeId, 1)
        assert.equal(created.status, 'processing')
        assert.equal(created.stockLedger.length, 1)
        assert.equal(created.stockLedger[0].type, 'reserved')
        assert.equal(created.payment.status, 'pending')
        await route.abort()
      })
      // 先註冊等待再按下付款，確認兩個後續讀取皆已發出。
      const refreshes = refreshPaths.map((path) => page.waitForRequest((request) => (
        request.method() === 'GET' && new URL(request.url()).pathname === path
      )))
      await Promise.all([...refreshes, page.getByRole('button', { name: '發起付款', exact: true }).click()])
      page.off('request', recordPaymentRefresh)
      page.off('response', recordPaymentReadback)
      assert.equal(refreshSequence[0], paymentDetailPath)
      await page.getByRole('status').filter({
        hasText: '付款操作結果尚未確認。同一筆付款仍在處理中。可查看待處理原因或手動查詢付款結果。',
      }).waitFor()
      assert.match(paymentId, /^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i)
      assert.equal(await page.getByText(paymentId, { exact: true }).count(), 1)
      assert.equal(paymentStartPostCount, 1)
      assert.equal(await page.getByRole('heading', { name: '總部模擬付款', exact: true }).count(), 0)
      await expectInventoryBalances(page, 12, 9, 3, 2)
      await page.unroute(paymentStartPath)
    } else {
      await expectInventory(page, coffee, cookies)
    }
    await expectLogout(page)
  }

  // 回到原總部身分後，先驗證待辦仍在，再經一次 419 恢復原始本文。
  phase = '驗證明確重送、查單、取消與庫存釋放'
  await submitLogin(page, emails[0], accounts[emails[0]])
  await page.getByRole('heading', { name: '門市訂單', exact: true }).waitFor()
  await page.route(`${origin}/api/orders`, interceptOriginalOrderPosts)
  const confirmation = page.getByRole('button', { name: '確認同一筆訂單', exact: true })
  await confirmation.waitFor()
  assert.equal(orderPostCount, 1)
  await confirmation.click()
  await page.getByRole('status').filter({ hasText: '登入狀態已更新。' }).waitFor()
  await page.getByRole('button', { name: '確認同一筆訂單', exact: true }).waitFor()
  assert.equal(orderPostCount, 2)
  assert.equal(orderRequestBodies[1], orderRequestBodies[0])
  await expectInventoryBalances(page, 12, 9, 3, 2)
  assert.equal(orderPostCount, 2)

  // 後端已提交重送，但不完整的回應不得被當成確認，也不得清掉原始待辦。
  await confirmation.click()
  await page.getByRole('status').filter({
    hasText: '建立結果尚未確認。請使用原內容確認同一筆訂單。',
  }).waitFor()
  await confirmation.waitFor()
  assert.equal(orderPostCount, 3)
  assert.equal(orderRequestBodies[2], orderRequestBodies[0])
  const retainedPending = await page.evaluate(() => (
    Object.entries(sessionStorage).filter(([key]) => key.startsWith('store-ordering:pending-order:'))
  ))
  assert.equal(retainedPending.length, 1)
  assert.match(retainedPending[0][0], /^store-ordering:pending-order:\d+:1$/)
  assert.equal(retainedPending[0][1], orderRequestBodies[0])

  // 重載確認原始操作仍待明確確認，期間沒有自動重送。
  await page.reload()
  await page.getByRole('button', { name: '確認同一筆訂單', exact: true }).waitFor()
  assert.equal(orderPostCount, 3)
  assert.deepEqual(
    await page.evaluate(() => (
      Object.entries(sessionStorage).filter(([key]) => key.startsWith('store-ordering:pending-order:'))
    )),
    retainedPending,
  )

  // 再次明確確認後才接受未修改的真實 200，並比對原始建立回應中的單號。
  await page.unroute(`${origin}/api/orders`)
  const replayResponsePromise = page.waitForResponse((response) => (
    response.url() === `${origin}/api/orders` && response.request().method() === 'POST'
  ))
  await page.getByRole('button', { name: '確認同一筆訂單', exact: true }).click()
  const replayResponse = await replayResponsePromise
  assert.equal(replayResponse.status(), 200)
  const replayedOrder = (await replayResponse.json()).data
  const orderId = replayedOrder.id
  assert.equal(orderId, committedOrderId)
  assert.equal(orderRequestBodies[3], orderRequestBodies[0])
  assert.equal(replayedOrder.total, 150)
  await page.getByRole('status').filter({ hasText: `已確認訂單 #${orderId}。` }).waitFor()

  // 訂單保留只減少可售量，實存仍維持原數字；明細顯示後端價格快照及歷程。
  await page.getByRole('heading', { name: `訂單 #${orderId}`, exact: true }).waitFor()
  await expectInventoryBalances(page, 12, 9, 3, 2)
  const detail = page.getByRole('region', { name: `訂單 #${orderId}` })
  await detail.getByText('150 元', { exact: true }).waitFor()
  await detail.getByText(/濾掛咖啡 × 2.*單價 45 元，小計 90 元/).waitFor()
  await detail.getByText(/燕麥餅乾 × 1.*單價 60 元，小計 60 元/).waitFor()
  assert.match((await detail.locator('ol').nth(0).innerText()).replace(/\s+/g, ' '), /待付款/)
  assert.deepEqual(
    (await detail.locator('ol').nth(1).locator('li').allTextContents())
      .map((text) => text.replace(/\s+/g, ' ').trim().split(' · ')[0]),
    ['保留 濾掛咖啡 × 2', '保留 燕麥餅乾 × 1'],
  )
  await expectOrderLayout(page)

  // 以台北日期區間、狀態及單號篩選，避免歷史資料影響結果數量。
  const dateParts = Object.fromEntries(new Intl.DateTimeFormat('en', {
    timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(new Date(replayedOrder.createdAt)).map(({ type, value }) => [type, value]))
  const fromDate = `${dateParts.year}-${dateParts.month}-${dateParts.day}`
  const nextDate = new Date(Date.UTC(Number(dateParts.year), Number(dateParts.month) - 1, Number(dateParts.day) + 1))
  const toDate = [nextDate.getUTCFullYear(), String(nextDate.getUTCMonth() + 1).padStart(2, '0'),
    String(nextDate.getUTCDate()).padStart(2, '0')].join('-')
  await page.getByLabel('訂單編號', { exact: true }).fill(String(orderId))
  await page.getByLabel('訂單狀態', { exact: true }).selectOption('awaiting_payment')
  await page.getByLabel('建立起日（含）', { exact: true }).fill(fromDate)
  await page.getByLabel('建立迄日（不含）', { exact: true }).fill(toDate)
  await page.getByRole('button', { name: '套用篩選', exact: true }).click()
  const orderList = page.getByRole('list', { name: '訂單查詢結果' })
  await orderList.getByRole('button', { name: `查看訂單 #${orderId}`, exact: true }).waitFor()
  assert.deepEqual(
    (await orderList.getByRole('button').allTextContents()).map((text) => text.trim()),
    [`查看訂單 #${orderId}`],
  )
  const viewOrder = orderList.getByRole('button', { name: `查看訂單 #${orderId}`, exact: true })
  // 從篩選按鈕以 Tab 經過清除控制項，讓查詢結果按鈕取得鍵盤焦點。
  await page.keyboard.press('Tab')
  await page.keyboard.press('Tab')
  // 確認鍵盤聚焦的原生查詢按鈕顯示可見外框。
  assert.equal(await viewOrder.evaluate((button) => (
    button === document.activeElement
      && button.matches(':focus-visible')
      && getComputedStyle(button).outlineStyle === 'solid'
  )), true)
  await viewOrder.press('Enter')
  const orderHeading = page.getByRole('heading', { name: `訂單 #${orderId}`, exact: true })
  await orderHeading.waitFor()
  // 開啟詳情後確認焦點移至訂單標題，並保留可見外框。
  assert.equal(await orderHeading.evaluate((heading) => heading === document.activeElement), true)
  assert.equal(await orderHeading.evaluate((heading) => (
    heading.matches(':focus-visible') && getComputedStyle(heading).outlineStyle === 'solid'
  )), true)

  // 取消後回讀同一訂單與庫存，確認歷程加入取消及釋放紀錄。
  await page.getByRole('button', { name: '取消訂單', exact: true }).click()
  await page.getByRole('status').filter({ hasText: '已取消訂單。' }).waitFor()
  await detail.getByText('已取消', { exact: true }).waitFor()
  assert.deepEqual(
    (await detail.locator('ol').nth(0).locator('li').allTextContents())
      .map((text) => text.replace(/\s+/g, ' ').trim().split(' · ')[0]),
    ['待付款', '已取消'],
  )
  assert.deepEqual(
    (await detail.locator('ol').nth(1).locator('li').allTextContents())
      .map((text) => text.replace(/\s+/g, ' ').trim().split(' · ')[0]),
    [
      '保留 濾掛咖啡 × 2', '保留 燕麥餅乾 × 1',
      '釋放 濾掛咖啡 × 2', '釋放 燕麥餅乾 × 1',
    ],
  )
  await expectInventoryBalances(page, 12, 11, 3, 3)

  // HQ 面板跨門市維持可用；切到另一店仍可決定此筆待處理付款。
  const paymentPanel = page.getByRole('region', { name: '總部模擬付款' })
  await paymentPanel.getByRole('button', { name: '重新載入付款', exact: true }).click()
  const pendingPaymentRow = paymentPanel.getByRole('row').filter({ hasText: paymentId })
  await pendingPaymentRow.getByText('待決定', { exact: true }).waitFor()
  assert.deepEqual(await pendingPaymentRow.getByRole('button').allTextContents(), ['決定成功', '決定失敗'])
  await expectPaymentPanelLayout(page, paymentId)
  await page.getByLabel('門市', { exact: true }).selectOption({ label: '台中門市' })
  await expectInventory(page, 4, 0)
  await pendingPaymentRow.getByText('待決定', { exact: true }).waitFor()
  const decideFailed = pendingPaymentRow.getByRole('button', { name: '決定失敗', exact: true })
  await decideFailed.focus()
  await page.keyboard.press('Enter')
  await pendingPaymentRow.getByText('付款失敗', { exact: true }).waitFor()
  const decisionFeedback = paymentPanel.getByRole('status').filter({ hasText: '已重新讀取付款狀態：付款失敗。' })
  await decisionFeedback.waitFor()
  assert.equal(await decisionFeedback.evaluate((element) => element === document.activeElement), true)
  assert.equal(await pendingPaymentRow.getByRole('button', { name: '決定成功', exact: true }).count(), 0)
  assert.equal(await pendingPaymentRow.getByRole('button', { name: '決定失敗', exact: true }).count(), 0)
  assert.deepEqual(await pendingPaymentRow.getByRole('button').allTextContents(), [
    '立即送出／重送', '延遲 60 秒送出',
  ])
  await expectPaymentPanelLayout(page, paymentId)

  // 回到原門市，以單號追蹤同一訂單在清單中的處理中與付款失敗狀態。
  await page.getByLabel('門市', { exact: true }).selectOption({ label: '台北門市' })
  await expectInventoryBalances(page, 12, 11, 3, 3)
  await page.getByLabel('訂單編號', { exact: true }).fill(String(paymentOrderId))
  await page.getByRole('button', { name: '套用篩選', exact: true }).click()
  const paymentOrderButton = page.getByRole('button', { name: `查看訂單 #${paymentOrderId}`, exact: true })
  const paymentOrderRow = orderList.getByRole('listitem').filter({ has: paymentOrderButton })

  // 初次讀取尚無已知識別，單元素 UUID 陣列也必須被回應驗證拒收。
  phase = '驗證首次讀取拒絕陣列付款識別'
  const paymentDetailUrl = `${origin}/api/orders/${paymentOrderId}`
  await page.route(paymentDetailUrl, (route) => fulfillPaymentIdentity(route, [paymentId]), { times: 1 })
  await paymentOrderButton.click()
  const paymentDetail = page.getByRole('region', { name: `訂單 #${paymentOrderId}` })
  const detailReadError = paymentDetail.getByRole('alert').filter({ hasText: '無法讀取訂單詳情，請重試。' })
  const retryDetail = paymentDetail.getByRole('button', { name: '重新讀取訂單詳情', exact: true })
  await detailReadError.waitFor()
  assert.equal(await paymentDetail.locator('.payment-id').count(), 0)
  await retryDetail.click()
  await paymentDetail.getByText(paymentId, { exact: true }).waitFor()
  const paymentQueryPath = `${paymentDetailUrl}/payment/query`
  page.on('request', (request) => {
    if (request.url() === paymentQueryPath && request.method() === 'POST') paymentQueryPostCount++
  })
  const queryButton = paymentDetail.getByRole('button', { name: '查詢付款結果', exact: true })
  // 重試按鈕已移除，從詳情標題開始驗證付款操作的鍵盤順序。
  await paymentDetail.getByRole('heading', { name: `訂單 #${paymentOrderId}`, exact: true }).focus()
  await page.keyboard.press('Tab')
  assert.equal(await queryButton.evaluate((button) => (
    button === document.activeElement
      && button.matches(':focus-visible')
      && getComputedStyle(button).outlineStyle === 'solid'
  )), true)

  // 測試注入首次人工查詢與自動回讀失敗，將實際同步延後到兩個讀取完成。
  phase = '驗證付款晚於首次詳情回讀，手動重試更新清單與庫存'
  let queryCsrf
  await page.route(paymentQueryPath, async (route) => {
    queryCsrf = (await route.request().allHeaders())['x-csrf-token']
    await route.abort()
  }, { times: 1 })
  await page.route(paymentDetailUrl, (route) => route.abort(), { times: 1 })
  // 只等待單號篩選的實際清單讀取及本店庫存，確認付款仍未結算。
  const pendingList = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname === '/api/orders' && url.searchParams.get('orderNumber') === String(paymentOrderId)
  })
  const pendingStock = page.waitForResponse(`${origin}/api/stores/1/inventory?page=1`)
  await page.keyboard.press('Enter')
  const [listBeforeSettlement, stockBeforeSettlement] = await Promise.all([pendingList, pendingStock])
  assert.equal(listBeforeSettlement.status(), 200)
  assert.equal(stockBeforeSettlement.status(), 200)
  assert.equal((await listBeforeSettlement.json()).data[0]?.status, 'processing')
  // 濾掛咖啡仍保留一份，驗證回應是結算前的庫存。
  const { data: pendingInventory } = await stockBeforeSettlement.json()
  assert.equal(pendingInventory.find((item) => item.name === '濾掛咖啡')?.available, 11)
  await detailReadError.waitFor()
  await paymentOrderRow.getByText(/^付款處理中 · /).waitFor()
  await expectInventoryBalances(page, 12, 11, 3, 3)

  // 測試驅動程式用同一會話實際同步一次，模擬先前不明操作此時才在後端完成。
  assert.equal(typeof queryCsrf === 'string' && queryCsrf.length > 0, true)
  const lateSettlement = await context.request.post(paymentQueryPath, {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': queryCsrf },
    maxRedirects: 0,
  })
  assert.equal(lateSettlement.status(), 200)
  const { data: settledOrder } = await lateSettlement.json()
  assert.equal(settledOrder.status, 'failed')
  assert.equal(settledOrder.payment.id, paymentId)

  // 只重讀詳情即可顯示同一付款的終局與釋放後庫存，畫面未再送付款 POST。
  await retryDetail.click()
  await paymentDetail.getByText(paymentId, { exact: true }).waitFor()
  await paymentDetail.getByRole('status').filter({ hasText: '已回讀目前訂單狀態：付款失敗。' }).waitFor()
  await paymentOrderRow.getByText(/^付款失敗 · /).waitFor()
  await expectInventoryBalances(page, 12, 12, 3, 3)
  assert.equal(paymentQueryPostCount, 1)
  assert.equal(paymentStartPostCount, 1)
  const failedOrderSummary = await paymentDetail.locator('dl.order-summary dd').allTextContents()
  assert.equal(failedOrderSummary.filter((value) => value === '付款失敗').length, 2)
  assert.deepEqual(
    (await paymentDetail.locator('ol').nth(1).locator('li').allTextContents())
      .map((text) => text.replace(/\s+/g, ' ').trim().split(' · ')[0]),
    ['保留 濾掛咖啡 × 1', '釋放 濾掛咖啡 × 1'],
  )

  // 終局仍可正常查詢，顯示明確回饋且庫存不再變動。
  await queryButton.click()
  await paymentDetail.getByRole('status').filter({ hasText: '已確認付款失敗，保留庫存已釋放。' }).waitFor()
  assert.equal(paymentQueryPostCount, 2)
  assert.equal(await paymentDetail.getByRole('button', { name: '發起付款', exact: true }).count(), 0)
  await expectInventoryBalances(page, 12, 12, 3, 3)

  // 終局查詢及兩次回讀都換成與原識別不同的 UUID，確認讀取失敗後仍保有原識別。
  phase = '驗證人工查詢後連續回讀仍拒絕不同付款識別'
  const wrongPaymentId = `${paymentId[0] === '0' ? '1' : '0'}${paymentId.slice(1)}`
  await page.route(paymentQueryPath, (route) => fulfillPaymentIdentity(route, wrongPaymentId), { times: 1 })
  await page.route(paymentDetailUrl, (route) => fulfillPaymentIdentity(route, wrongPaymentId), { times: 2 })
  const repeatedQuery = page.waitForResponse((response) => (
    response.url() === paymentQueryPath && response.request().method() === 'POST'
  ))
  await paymentDetail.getByRole('button', { name: '查詢付款結果', exact: true }).click()
  assert.equal((await repeatedQuery).status(), 200)
  await detailReadError.waitFor()
  const rejectedRetry = page.waitForResponse((response) => response.url() === paymentDetailUrl)
  await retryDetail.click()
  assert.equal((await rejectedRetry).status(), 200)
  await detailReadError.waitFor()
  assert.equal(await paymentDetail.locator('.payment-id').count(), 0)

  // 原識別的真實回應仍可恢復詳情，查詢不會重送或重複釋放庫存。
  await retryDetail.click()
  await paymentDetail.getByText(paymentId, { exact: true }).waitFor()
  await paymentDetail.getByRole('status').filter({ hasText: '已回讀目前訂單狀態：付款失敗。' }).waitFor()
  const repeatedFailedSummary = await paymentDetail.locator('dl.order-summary dd').allTextContents()
  assert.equal(repeatedFailedSummary.filter((value) => value === '付款失敗').length, 2)
  assert.equal(paymentQueryPostCount, 3)
  assert.equal(paymentStartPostCount, 1)
  assert.equal(await paymentDetail.locator('ol').nth(1).locator('li').count(), 2)
  await expectInventoryBalances(page, 12, 12, 3, 3)

  // 付款操作後直接建另一筆訂單，該筆詳情只顯示自己的狀態。
  phase = '驗證新建訂單不沿用上一筆付款回饋'
  await page.getByLabel('商品', { exact: true }).selectOption({ label: '濾掛咖啡' })
  await page.getByRole('button', { name: '新增品項', exact: true }).click()
  const replacementResponse = page.waitForResponse((response) => (
    response.url() === `${origin}/api/orders` && response.request().method() === 'POST'
  ))
  await page.getByRole('button', { name: '建立訂單', exact: true }).click()
  const replacement = await replacementResponse
  assert.equal(replacement.status(), 201)
  replacementOrderId = (await replacement.json()).data.id
  assert.notEqual(replacementOrderId, paymentOrderId)
  const replacementDetail = page.getByRole('region', { name: `訂單 #${replacementOrderId}` })
  await replacementDetail.getByText('尚未建立付款', { exact: true }).waitFor()
  const replacementFeedback = await replacementDetail.getByRole('status').allTextContents()

  // 先取消新建測試訂單並核對庫存恢復，再檢查剛才記錄的付款回饋。
  await replacementDetail.getByRole('button', { name: '取消訂單', exact: true }).click()
  await replacementDetail.locator('dl.order-summary').getByText('已取消', { exact: true }).waitFor()
  await expectInventoryBalances(page, 12, 12, 3, 3)
  assert.deepEqual(replacementFeedback, [])

  // 以目前 HQ 會話建立失敗付款，供通知派送驗收使用且不重設共享資料。
  phase = '驗證總部延遲通知、回應遺失後明確重送'
  const sessionResponse = await context.request.get('/api/session', {
    headers: { Accept: 'application/json' },
    maxRedirects: 0,
  })
  assert.equal(sessionResponse.status(), 200)
  const session = await sessionResponse.json()
  assert.equal(session.user.role, 'headquarters')
  const csrfToken = session.csrfToken
  assert.equal(typeof csrfToken === 'string' && csrfToken.length > 0, true)

  const inventoryResponse = await context.request.get('/api/stores/1/inventory?page=1', {
    headers: { Accept: 'application/json' },
    maxRedirects: 0,
  })
  assert.equal(inventoryResponse.status(), 200)
  const inventoryBeforeDispatch = (await inventoryResponse.json()).data
  const coffee = inventoryBeforeDispatch.find(({ name }) => name === '濾掛咖啡')
  const cookies = inventoryBeforeDispatch.find(({ name }) => name === '燕麥餅乾')
  assert.ok(coffee?.available > 0)
  assert.ok(cookies)

  const createdResponse = await context.request.post('/api/orders', {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
    data: {
      storeId: 1,
      operationKey: randomUUID(),
      items: [{ productId: coffee.id, quantity: 1 }],
    },
    maxRedirects: 0,
  })
  assert.equal(createdResponse.status(), 201)
  const createdFixture = (await createdResponse.json()).data
  dispatchOrderId = createdFixture.id
  assert.equal(createdFixture.status, 'awaiting_payment')

  const startFixtureResponse = await context.request.post(`${origin}/api/orders/${dispatchOrderId}/payment`, {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
    maxRedirects: 0,
  })
  assert.equal(startFixtureResponse.status(), 200)
  const startedFixture = (await startFixtureResponse.json()).data
  assert.equal(startedFixture.status, 'processing')
  dispatchPaymentId = startedFixture.payment.id

  const decisionFixtureResponse = await context.request.post(
    `${origin}/api/mock-payments/${dispatchPaymentId}/decision`,
    {
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
      data: { status: 'failed' },
      maxRedirects: 0,
    },
  )
  assert.equal(decisionFixtureResponse.status(), 200)
  assert.equal((await decisionFixtureResponse.json()).data.status, 'failed')

  const dispatchEndpoint = `${origin}/api/mock-payments/${dispatchPaymentId}/dispatch`
  const dispatchBodies = []
  let fixtureQueryCount = 0
  // 計算派送 POST 以驗證人工重送，並確認付款查詢 POST 未介入通知結算。
  page.on('request', (request) => {
    if (request.url() === dispatchEndpoint && request.method() === 'POST') {
      dispatchBodies.push(JSON.parse(request.postData()))
    }
    if (request.url() === `${origin}/api/orders/${dispatchOrderId}/payment/query`) {
      fixtureQueryCount++
    }
  })

  await paymentPanel.getByRole('button', { name: '重新載入付款', exact: true }).click()
  const dispatchRow = paymentPanel.getByRole('row').filter({ hasText: dispatchPaymentId })
  await dispatchRow.getByText('付款失敗', { exact: true }).waitFor()
  assert.deepEqual(await dispatchRow.getByRole('button').allTextContents(), [
    '立即送出／重送', '延遲 60 秒送出',
  ])
  await expectPaymentPanelLayout(page, dispatchPaymentId)

  // 延遲派送需收到真實 202 並明確顯示排入佇列，不宣稱通知已送達。
  const delayedResponsePromise = page.waitForResponse((response) => (
    response.url() === dispatchEndpoint && response.request().method() === 'POST'
  ))
  const immediateButton = dispatchRow.getByRole('button', { name: '立即送出／重送', exact: true })
  const delayedButton = dispatchRow.getByRole('button', { name: '延遲 60 秒送出', exact: true })
  await immediateButton.focus()
  await page.keyboard.press('Tab')
  assert.equal(await delayedButton.evaluate((button) => (
    button === document.activeElement
      && button.matches(':focus-visible')
      && getComputedStyle(button).outlineStyle === 'solid'
  )), true)
  await page.keyboard.press('Enter')
  const delayedResponse = await delayedResponsePromise
  assert.equal(delayedResponse.status(), 202)
  const delayedDispatch = (await delayedResponse.json()).data
  assert.match(delayedDispatch.eventId, /^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i)
  assert.equal(delayedDispatch.mode, 'delayed')
  assert.deepEqual(dispatchBodies, [{ mode: 'delayed' }])
  const delayedFeedback = paymentPanel.getByRole('status').filter({
    hasText: `通知事件 ${delayedDispatch.eventId} 已排入 60 秒後派送佇列。`,
  })
  await delayedFeedback.waitFor()
  assert.equal(await delayedFeedback.evaluate((element) => element === document.activeElement), true)
  const orderAfterDelayResponse = await context.request.get(`/api/orders/${dispatchOrderId}`, {
    headers: { Accept: 'application/json' },
    maxRedirects: 0,
  })
  assert.equal(orderAfterDelayResponse.status(), 200)
  assert.equal((await orderAfterDelayResponse.json()).data.status, 'processing')

  // 讓真正成功排入的立即重送遺失瀏覽器回應，確認畫面保留未知狀態且不自動再送。
  let lostDispatch
  await page.route(dispatchEndpoint, async (route) => {
    const response = await route.fetch()
    assert.equal(response.status(), 202)
    lostDispatch = await response.json()
    await route.abort()
  }, { times: 1 })
  await immediateButton.focus()
  await page.keyboard.press('Enter')
  const unknownDispatchFeedback = paymentPanel.getByRole('status').filter({
    hasText: '通知派送結果尚未確認，可能已排入佇列。',
  })
  await unknownDispatchFeedback.waitFor()
  assert.equal(await unknownDispatchFeedback.evaluate((element) => element === document.activeElement), true)
  assert.equal(dispatchBodies.length, 2)
  assert.equal(dispatchBodies[1].mode, 'immediate')
  assert.equal(lostDispatch.data.mode, 'immediate')
  assert.equal(lostDispatch.data.eventId, delayedDispatch.eventId)
  assert.equal(fixtureQueryCount, 0)

  // 只有再按一次立即重送後才送第二次該操作，回應仍須指向原事件。
  const immediateResponsePromise = page.waitForResponse((response) => (
    response.url() === dispatchEndpoint && response.request().method() === 'POST'
  ))
  await immediateButton.focus()
  await page.keyboard.press('Enter')
  const immediateResponse = await immediateResponsePromise
  assert.equal(immediateResponse.status(), 202)
  const immediateDispatch = (await immediateResponse.json()).data
  assert.equal(immediateDispatch.mode, 'immediate')
  assert.equal(immediateDispatch.eventId, delayedDispatch.eventId)
  assert.deepEqual(dispatchBodies, [
    { mode: 'delayed' }, { mode: 'immediate' }, { mode: 'immediate' },
  ])
  const immediateFeedback = paymentPanel.getByRole('status').filter({
    hasText: `通知事件 ${delayedDispatch.eventId} 已排入立即派送佇列。`,
  })
  await immediateFeedback.waitFor()
  assert.equal(await immediateFeedback.evaluate((element) => element === document.activeElement), true)

  const dispatchedOrder = await waitForOrderStatus(context, dispatchOrderId, 'failed')
  assert.equal(dispatchedOrder.payment.id, dispatchPaymentId)
  assert.equal(dispatchedOrder.payment.status, 'failed')
  assert.equal(dispatchedOrder.history.filter(({ status }) => status === 'failed').length, 1)
  assert.equal(dispatchedOrder.stockLedger.filter(({ type }) => type === 'reserved').length, 1)
  assert.equal(dispatchedOrder.stockLedger.filter(({ type }) => type === 'released').length, 1)
  assert.equal(dispatchedOrder.stockLedger.filter(({ type }) => type === 'deducted').length, 0)
  assert.equal(fixtureQueryCount, 0)

  // 用畫面實際切店重讀庫存，再從訂單篩選、開啟同一失敗訂單詳情。
  const storePicker = page.getByLabel('門市', { exact: true })
  await storePicker.selectOption({ label: '台中門市' })
  await expectInventory(page, 4, 0)
  await storePicker.selectOption({ label: '台北門市' })
  await expectInventoryBalances(page, coffee.onHand, coffee.available, cookies.onHand, cookies.available)
  await page.getByLabel('訂單編號', { exact: true }).fill(String(dispatchOrderId))
  await page.getByLabel('訂單狀態', { exact: true }).selectOption('failed')
  await page.getByLabel('建立起日（含）', { exact: true }).fill('')
  await page.getByLabel('建立迄日（不含）', { exact: true }).fill('')
  await page.getByRole('button', { name: '套用篩選', exact: true }).click()
  const dispatchOrderList = page.getByRole('list', { name: '訂單查詢結果' })
  const dispatchOrderButton = dispatchOrderList.getByRole('button', {
    name: `查看訂單 #${dispatchOrderId}`,
    exact: true,
  })
  await dispatchOrderButton.waitFor()
  await dispatchOrderButton.click()
  const dispatchOrderDetail = page.getByRole('region', { name: `訂單 #${dispatchOrderId}` })
  await dispatchOrderDetail.getByText(dispatchPaymentId, { exact: true }).waitFor()
  assert.equal(await dispatchOrderDetail.locator('dl.order-summary dd').getByText('付款失敗', { exact: true }).count(), 2)
  assert.deepEqual(
    (await dispatchOrderDetail.locator('ol').nth(1).locator('li').allTextContents())
      .map((text) => text.replace(/\s+/g, ' ').trim().split(' · ')[0]),
    ['保留 濾掛咖啡 × 1', '釋放 濾掛咖啡 × 1'],
  )
  assert.equal(fixtureQueryCount, 0)

  const inventoryAfterDispatchResponse = await context.request.get('/api/stores/1/inventory?page=1', {
    headers: { Accept: 'application/json' },
    maxRedirects: 0,
  })
  assert.equal(inventoryAfterDispatchResponse.status(), 200)
  const coffeeAfterDispatch = (await inventoryAfterDispatchResponse.json()).data
    .find(({ id }) => id === coffee.id)
  assert.equal(coffeeAfterDispatch.available, coffee.available)

  // 確認主要流程沒有未捕捉的頁面例外。
  phase = '確認頁面執行狀態'
  assert.deepEqual(pageErrors, [])
  console.log('PASS：登入、登出、重載、總部與兩店庫存、訂單／付款查詢、通知延遲及重送、焦點與 320/1280 手機／桌機版面。')
} catch (error) {
  // Playwright 的動作紀錄可能包含欄位值及 API 會話標頭，輸出前先遮蔽。
  let diagnostic = error instanceof Error ? error.message : '未知錯誤'
  diagnostic = diagnostic.replace(/^([ \t-]*(?:cookie|set-cookie|x-csrf-token):)[^\r\n]*/gim, '$1 [已遮蔽]')
  for (const secret of fixturePasswords) {
    const escaped = JSON.stringify(secret).slice(1, -1)
    diagnostic = diagnostic.replaceAll(secret, '[已遮蔽]').replaceAll(escaped, '[已遮蔽]')
  }
  console.error(`FAIL：${phase}\n${diagnostic}`)
  console.error('已知測試訂單編號', { committedOrderId, paymentOrderId, replacementOrderId, dispatchOrderId })
  process.exitCode = 1
} finally {
  await browser?.close()
}
