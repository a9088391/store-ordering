import { ApiError } from './api'

export type WorkspacePage = 'inventory' | 'list' | 'create' | 'detail' | 'payments'

export interface OrderScope {
  actorId: number
  storeId: number
  signal: AbortSignal
  csrfToken: () => string
  writeBusy: () => boolean
  sessionExpired: () => void
  stockChanged: () => void
}

// 只有會話或防偽驗證失效才交給上層重新確認身分。
export function needsSession(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.status === 419)
}
