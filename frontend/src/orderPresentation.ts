import type { OrderStatus, PaymentAnomaly, PendingPaymentReason } from './api'

export const statusLabels: Record<OrderStatus, string> = {
  awaiting_payment: '待付款',
  processing: '付款處理中',
  paid: '已付款',
  failed: '付款失敗',
  cancelled: '已取消',
  expired: '已到期',
}
export const paymentStatusLabels = { pending: '待確認', paid: '已付款', failed: '付款失敗' }
export const pendingReasonLabels: Record<Exclude<PendingPaymentReason, null>, string> = {
  creation_unconfirmed: '付款建立尚未確認',
  awaiting_result: '等待模擬付款結果',
  service_unavailable: '模擬付款服務暫時無法使用',
  payment_not_found: '尚未查到遠端付款',
  invalid_response: '付款回應內容待確認',
  recovery_exhausted: '自動補辦已達上限，請查詢付款結果。',
}
export const anomalyLabels: Record<PaymentAnomaly['reason'], string> = {
  invalid_response: '付款回應無效',
  payment_id_mismatch: '付款識別不符',
  order_id_mismatch: '訂單識別不符',
  amount_mismatch: '付款金額不符',
  currency_mismatch: '付款幣別不符',
  terminal_conflict: '付款終局狀態衝突',
  event_content_conflict: '付款通知事件內容衝突',
}
export const numberFormat = new Intl.NumberFormat('zh-TW')
export const dateFormat = new Intl.DateTimeFormat('zh-TW', {
  timeZone: 'Asia/Taipei',
  dateStyle: 'medium',
  timeStyle: 'medium',
  hourCycle: 'h23',
})

export const stockMovementLabels = { reserved: '保留', released: '釋放', deducted: '扣除' }
