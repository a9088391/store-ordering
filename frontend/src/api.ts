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
async function request<T>(path: string, options?: RequestInit): Promise<T> {
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
