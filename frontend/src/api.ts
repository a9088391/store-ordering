type SessionUser = {
  id: number
  name: string
} & ({ role: 'headquarters'; storeId: null } | { role: 'staff'; storeId: number })

export interface Session {
  user: SessionUser | null
  csrfToken: string
}

export interface Credentials {
  email: string
  password: string
}

export interface Store {
  id: number
  name: string
}

export interface InventoryItem {
  id: number
  name: string
  price: number
  onHand: number
  available: number
}

export interface InventoryPage {
  data: InventoryItem[]
  meta: { page: number; perPage: number; total: number }
}

const orderStatuses = ['awaiting_payment', 'processing', 'paid', 'failed', 'cancelled', 'expired'] as const
export type OrderStatus = typeof orderStatuses[number]

export interface OrderFilters {
  orderNumber: string
  status: OrderStatus | ''
  from: string
  to: string
}

export interface OrderItemInput {
  productId: number
  quantity: number
}

export interface OrderDraft {
  storeId: number
  operationKey: string
  items: OrderItemInput[]
}

interface OrderSummary {
  id: number
  storeId: number
  createdBy: number
  status: OrderStatus
  total: number
  createdAt: string
  expiresAt: string
}

export interface OrderPage {
  data: OrderSummary[]
  meta: InventoryPage['meta']
}

export interface OrderDetail extends OrderSummary {
  items: (OrderItemInput & { name: string; unitPrice: number; subtotal: number })[]
  history: { status: OrderStatus; occurredAt: string }[]
  stockLedger: (OrderItemInput & { type: 'reserved' | 'released'; occurredAt: string })[]
  payment: null
  anomalies: []
}

export class ApiError extends Error {
  // 保留可分流的協定資訊，避免把伺服器例外或請求內容交給畫面。
  constructor(
    readonly status: number,
    readonly code: string | undefined,
    readonly retryAfter: number,
  ) {
    super(`HTTP ${status}`)
  }
}

// Laravel 提供同來源 cookie session；遇到非 API 重新導向時停止讀取。
async function request<T>(path: string, options?: RequestInit, expectedStatuses?: number[]): Promise<T> {
  const response = await fetch(`/api${path}`, {
    ...options,
    mode: 'same-origin',
    redirect: 'error',
    headers: { Accept: 'application/json', ...options?.headers },
  })

  // 穩定錯誤代碼供畫面區分可修正的輸入與仍需確認的結果。
  if (!response.ok) {
    const failure = await response.json()
      // 非 JSON 的錯誤仍保留 HTTP 狀態，但不推定其業務結果。
      .catch(() => null) as { code?: string } | null
    throw new ApiError(response.status, failure?.code, Number(response.headers.get('Retry-After')))
  }

  // 寫入回應必須使用契約的成功狀態；其他狀態仍需要確認結果。
  if (expectedStatuses && !expectedStatuses.includes(response.status)) {
    throw new Error('API 成功狀態與契約不符。')
  }
  return await response.json() as T
}

// 身分會控制受保護畫面，只有完整的角色、門市和帳號資訊可用來切換。
function isSessionUser(user: unknown): user is SessionUser {
  if (typeof user !== 'object' || user === null) return false

  const candidate = user as Partial<SessionUser>
  return (
    typeof candidate.id === 'number'
    && Number.isSafeInteger(candidate.id)
    && typeof candidate.name === 'string'
    && (
      (candidate.role === 'headquarters' && candidate.storeId === null)
      || (candidate.role === 'staff' && Number.isSafeInteger(candidate.storeId))
    )
  )
}

// 會話回應若不完整，交由上層重新確認，不沿用原有身分或 CSRF token。
async function requestSession(path: string, options: RequestInit): Promise<Session> {
  const session = await request<Session | null>(path, options)
  if (
    typeof session?.csrfToken !== 'string'
    || session.csrfToken.length === 0
    || (session.user !== null && !isSessionUser(session.user))
  ) {
    throw new Error('會話回應不完整。')
  }

  return session
}

// 讀取目前會話與 token，供初始載入或不明結果時確認。
export function getSession(signal: AbortSignal): Promise<Session> {
  return requestSession('/session', { signal })
}

// 憑證只放在同來源 JSON 本文，以目前會話的 token 通過 CSRF 驗證。
export function login(
  credentials: Credentials,
  csrfToken: string,
  signal: AbortSignal,
): Promise<Session> {
  return requestSession('/login', {
    method: 'POST',
    body: JSON.stringify(credentials),
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    signal,
  })
}

// 登出也需 CSRF token；回傳新匿名會話供下一次登入使用。
export function logout(csrfToken: string, signal: AbortSignal): Promise<Session> {
  return requestSession('/logout', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': csrfToken },
    signal,
  })
}

// 門市清單直接使用後端授權結果。
export async function getStores(signal: AbortSignal): Promise<Store[]> {
  const result = await request<{ data: Store[] }>('/stores', { signal })
  return result.data
}

// 以門市及頁碼讀取庫存，讓畫面切換時可取消已不需要的查詢。
export function getInventory(
  storeId: number,
  page: number,
  signal: AbortSignal,
): Promise<InventoryPage> {
  return request<InventoryPage>(`/stores/${storeId}/inventory?page=${page}`, { signal })
}

// 只接受可無損傳給後端、或從後端顯示的正整數。
export function isPositiveSafeInteger(value: unknown): value is number {
  return (
    typeof value === 'number'
    && Number.isSafeInteger(value)
    && value > 0
  )
}

// 核對保存的操作本文仍符合目前門市及品項限制，避免重送不完整資料。
export function readOrderDraft(body: string, storeId: number): OrderDraft {
  const draft = JSON.parse(body) as OrderDraft | null
  if (
    draft?.storeId !== storeId
    || typeof draft.operationKey !== 'string'
    || draft.operationKey.length === 0
    || !Array.isArray(draft.items)
    || draft.items.length === 0
    || draft.items.length > 100
  ) throw new Error('待確認訂單資料不完整。')

  // 商品不可重複；使用原始本文重送，保留相同操作鍵與內容。
  const products = new Set<number>()
  for (const item of draft.items) {
    if (
      !isPositiveSafeInteger(item?.productId)
      || !isPositiveSafeInteger(item.quantity)
      || products.has(item.productId)
    ) throw new Error('待確認訂單品項不完整。')
    products.add(item.productId)
  }
  return draft
}

// 驗證日期可顯示為台北時間，且 API 保持 UTC 時間格式。
function isUtcTimestamp(value: unknown): boolean {
  return (
    typeof value === 'string'
    && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/.test(value)
    && Number.isFinite(Date.parse(value))
  )
}

// 門市範圍及摘要欄位相符，才可顯示訂單或進行後續操作。
function isOrderSummary(order: OrderSummary | null | undefined, storeId: number): order is OrderSummary {
  return (
    order?.storeId === storeId
    && isPositiveSafeInteger(order.id)
    && isPositiveSafeInteger(order.createdBy)
    && isPositiveSafeInteger(order.total)
    && orderStatuses.includes(order.status)
    && isUtcTimestamp(order.createdAt)
    && isUtcTimestamp(order.expiresAt)
  )
}

// 每個品項都須有一次等量保留；本票的取消或到期另須有一次等量釋放。
function hasCompleteStockLedger(order: OrderDetail): boolean {
  // 建立各類異動應包含的品項，並先排除重複品項或筆數不符的回應。
  const reserved = new Map(order.items.map(({ productId, quantity }) => [productId, quantity]))
  const releasesStock = order.status === 'cancelled' || order.status === 'expired'
  const released = new Map(releasesStock ? reserved : undefined)
  if (
    reserved.size !== order.items.length
    || !Array.isArray(order.stockLedger)
    || order.stockLedger.length !== reserved.size + released.size
  ) return false

  // 每個符合數量與時間格式的異動只能用掉一筆預期紀錄。
  const remaining = { reserved, released }
  for (const entry of order.stockLedger) {
    if (entry?.type !== 'reserved' && entry?.type !== 'released') return false
    const expected = remaining[entry.type]
    if (
      !expected.has(entry.productId)
      || expected.get(entry.productId) !== entry.quantity
      || !isUtcTimestamp(entry.occurredAt)
    ) return false
    expected.delete(entry.productId)
  }
  return true
}

// 一併檢查明細及歷程結構，避免把不完整的成功回應當成已確認的寫入。
function isOrderDetail(order: OrderDetail | null | undefined, storeId: number): order is OrderDetail {
  return (
    isOrderSummary(order, storeId)
    && Array.isArray(order.items)
    && order.items.length > 0
    && order.items.length <= 100
    // 品項只接受後端保存的整數快照。
    && order.items.every((item) => (
      isPositiveSafeInteger(item?.productId)
      && isPositiveSafeInteger(item.quantity)
      && typeof item.name === 'string'
      && isPositiveSafeInteger(item.unitPrice)
      && isPositiveSafeInteger(item.subtotal)
    ))
    // 歷程沿用狀態與時間契約，庫存異動逐筆核對品項。
    && Array.isArray(order.history)
    && order.history.length > 0
    && order.history.every((event) => orderStatuses.includes(event?.status) && isUtcTimestamp(event.occurredAt))
    && hasCompleteStockLedger(order)
    && order.payment === null
    && Array.isArray(order.anomalies)
    && order.anomalies.length === 0
  )
}

// 只送出已套用且有值的篩選條件，回應必須對應此次頁碼及門市。
export async function getOrders(
  storeId: number,
  filters: OrderFilters,
  page: number,
  signal: AbortSignal,
): Promise<OrderPage> {
  const params = new URLSearchParams({ storeId: String(storeId), page: String(page) })
  for (const [name, value] of Object.entries(filters)) {
    if (value !== '') params.set(name, value)
  }

  // 確認分頁資訊與可見門市後才交給清單渲染。
  const result = await request<OrderPage | null>(`/orders?${params}`, { signal })
  if (
    !Array.isArray(result?.data)
    || result.meta?.page !== page
    || !isPositiveSafeInteger(result.meta.perPage)
    || result.meta.perPage > 100
    || (result.meta.total !== 0 && !isPositiveSafeInteger(result.meta.total))
    || !result.data.every((order) => isOrderSummary(order, storeId))
  ) throw new Error('訂單清單回應不完整。')
  return result
}

// 詳情以所選單號與目前門市核對，避免替換成其他訂單。
export async function getOrder(id: number, storeId: number, signal: AbortSignal): Promise<OrderDetail> {
  const result = await request<{ data: OrderDetail } | null>(`/orders/${id}`, { signal })
  if (!isOrderDetail(result?.data, storeId) || result.data.id !== id) {
    throw new Error('訂單詳情回應不完整。')
  }
  return result.data
}

// 首次建立與重送共用原始本文，並核對訂單確為該帳號提交的品項。
export async function createOrder(
  body: string,
  draft: OrderDraft,
  actorId: number,
  csrfToken: string,
  signal: AbortSignal,
): Promise<OrderDetail> {
  const result = await request<{ data: OrderDetail } | null>('/orders', {
    method: 'POST',
    body,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    signal,
  }, [200, 201])

  // 建單回應可能依商品識別重新排序；以品項識別核對內容。
  const submitted = new Map(draft.items.map(({ productId, quantity }) => [productId, quantity]))
  if (
    !isOrderDetail(result?.data, draft.storeId)
    || result.data.createdBy !== actorId
    || result.data.items.length !== submitted.size
  ) throw new Error('建單回應與提交內容不符。')

  // 每個已提交的商品都必須恰好出現一次。
  for (const { productId, quantity } of result.data.items) {
    if (submitted.get(productId) !== quantity) throw new Error('建單回應與提交品項不符。')
    submitted.delete(productId)
  }
  return result.data
}

// 成功取消只接受原訂單已進入取消狀態的回應。
export async function cancelOrder(
  id: number,
  storeId: number,
  csrfToken: string,
  signal: AbortSignal,
): Promise<OrderDetail> {
  const result = await request<{ data: OrderDetail } | null>(`/orders/${id}/cancel`, {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': csrfToken },
    signal,
  }, [200])
  if (
    !isOrderDetail(result?.data, storeId)
    || result.data.id !== id
    || result.data.status !== 'cancelled'
  ) throw new Error('取消回應與所選訂單不符。')
  return result.data
}
