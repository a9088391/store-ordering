import assert from 'node:assert/strict'
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

// 驗證登出後的登入表單可用，且重載不會再顯示上一位使用者的庫存。
async function expectLogout(page) {
  await page.getByRole('button', { name: '登出' }).click()
  await page.getByRole('heading', { name: '登入', exact: true }).waitFor()
  assert.equal(await page.getByRole('table').count(), 0)
  await page.reload()
  await page.getByRole('heading', { name: '登入', exact: true }).waitFor()
  assert.equal(await page.getByRole('table').count(), 0)
}

let phase = '啟動本機 Chrome'
let browser
const fixturePasswords = []

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
    assert.equal(await page.getByRole('combobox').count(), 0)
    await expectInventory(page, coffee, cookies)
    await expectLogout(page)
  }

  // 確認主要流程沒有未捕捉的頁面例外。
  phase = '確認頁面執行狀態'
  assert.deepEqual(pageErrors, [])
  console.log('PASS：登入、登出、重載、總部及兩店庫存、鍵盤焦點與手機版面。')
} catch (error) {
  // Playwright 的動作紀錄可能包含欄位值，印出診斷前先遮蔽所有測試密碼。
  let diagnostic = error instanceof Error ? error.message : '未知錯誤'
  for (const secret of fixturePasswords) {
    const escaped = JSON.stringify(secret).slice(1, -1)
    diagnostic = diagnostic.replaceAll(secret, '[已遮蔽]').replaceAll(escaped, '[已遮蔽]')
  }
  console.error(`FAIL：${phase}\n${diagnostic}`)
  process.exitCode = 1
} finally {
  await browser?.close()
}
