import { computed, onBeforeUnmount, ref, shallowRef } from 'vue'
import { ApiError, cancelOrder, getOrder, queryPayment, startPayment, type OrderDetail } from './api'
import { statusLabels } from './orderPresentation'
import { needsSession, type OrderScope } from './workspace'

type CancelResult = 'cancelled' | 'conflict' | 'unknown' | 'not-found'
export type PaymentAction = 'start' | 'query'
type PaymentResult = 'confirmed' | 'conflict' | 'unknown' | 'not-found'

// 訂單詳情負責回讀、取消及付款；付款識別保留到實際換單或離開門市。
export function useOrderDetail(scope: OrderScope, focusDetail: (id: number) => Promise<void>) {
  const selectedOrder = ref<number | null>(null)
  const detail = shallowRef<OrderDetail | null>(null)
  const detailError = ref('')
  const cancelling = ref(false)
  const cancelResult = ref<CancelResult | null>(null)
  const paymentAction = ref<PaymentAction | null>(null)
  const paymentResult = ref<PaymentResult | null>(null)
  let detailRead: AbortController | undefined
  // 付款識別屬於目前帳號、門市及選取的訂單，詳情讀取失敗時仍保留。
  let selectedPaymentId: string | undefined

  // 用回讀狀態說明原訂單的下一步，讀取失敗時維持結果待確認。
  const cancelFeedback = computed(() => {
    if (cancelResult.value === 'cancelled') return '已取消訂單。'
    if (cancelResult.value === 'conflict') return '訂單狀態已變更，請查看最新詳情。'
    if (cancelResult.value === 'not-found') return '找不到這筆訂單。'
    if (cancelResult.value !== 'unknown') return ''
    if (detail.value?.status === 'cancelled') return '已確認訂單取消。'
    if (detail.value?.status === 'awaiting_payment') {
      return '取消結果尚未確認。訂單目前仍為待付款，可以再次取消這筆訂單。'
    }
    return detail.value ? '已讀取訂單狀態，目前無法取消。' : '取消結果尚未確認，請確認這筆訂單的詳情。'
  })

  // 依安全回讀狀態提供下一步；處理中的付款仍可由背景補辦或手動查詢。
  const paymentFeedback = computed(() => {
    if (paymentResult.value === 'confirmed') {
      if (detail.value?.status === 'paid') return '已確認付款成功。'
      if (detail.value?.status === 'failed') return '已確認付款失敗，保留庫存已釋放。'
      // 讀取中或失敗時沒有可信的詳情，以讀取狀態與重試控制引導使用者。
      if (detail.value?.status === 'processing') return '已確認付款仍在處理中，庫存持續保留。'
      return ''
    }
    if (paymentResult.value === 'conflict') {
      return detail.value
        ? '付款狀態已變更，已重新讀取這筆訂單。'
        : '付款狀態發生衝突，詳情尚未確認，請重新讀取訂單。'
    }
    if (paymentResult.value === 'not-found') return '找不到這筆訂單，請重新讀取訂單清單。'
    if (paymentResult.value !== 'unknown') return ''
    if (detail.value?.status === 'awaiting_payment') {
      return '付款操作結果尚未確認。訂單仍待付款。請確認訂單後再手動發起。'
    }
    if (detail.value?.status === 'processing') {
      return '付款操作結果尚未確認。同一筆付款仍在處理中。可查看待處理原因或手動查詢付款結果。'
    }
    if (detail.value?.status === 'paid' || detail.value?.status === 'failed') {
      return `已回讀目前訂單狀態：${statusLabels[detail.value.status]}。可手動查詢最新付款結果。`
    }
    return '付款結果尚未確認。請重新讀取訂單詳情，再依目前狀態操作。'
  })

  // 詳情獨立取消過期讀取，並保留已知付款識別以核對同一筆交易。
  async function readDetail(id: number, recheckUnknown = false): Promise<void> {
    // 結束舊讀取，只有真正換單才清除已知付款識別。
    detailRead?.abort()
    const controller = new AbortController()
    detailRead = controller
    if (selectedOrder.value !== id) selectedPaymentId = undefined
    selectedOrder.value = id
    detail.value = null
    detailError.value = ''

    try {
      // 切換訂單、門市或帳號會中止舊讀取，只接受仍屬於目前詳情的結果。
      const order = await getOrder(id, scope.storeId, controller.signal)
      if (controller.signal.aborted) return
      if (selectedPaymentId && order.payment?.id !== selectedPaymentId) throw new Error('付款識別與先前讀取不符。')
      selectedPaymentId = order.payment?.id
      detail.value = order

      // 再次讀到未知操作的訂單時，同步更新可能已結算或釋放的庫存。
      if (recheckUnknown && (paymentResult.value === 'unknown' || cancelResult.value === 'unknown')) {
        scope.stockChanged()
      }
    } catch (error) {
      if (controller.signal.aborted) return
      if (needsSession(error)) scope.sessionExpired()
      else if (error instanceof ApiError && error.status === 404 && error.code === 'NOT_FOUND') {
        detailError.value = '找不到這筆訂單。'
      } else {
        detailError.value = '無法讀取訂單詳情，請重試。'
      }
    }
  }

  // 再次開啟同一筆詳情仍保留待確認回饋；不同訂單從乾淨狀態開始。
  function viewOrder(id: number): void {
    if (scope.writeBusy()) return
    const sameOrder = selectedOrder.value === id
    if (!sameOrder) {
      cancelResult.value = null
      paymentResult.value = null
    }
    void readDetail(id, sameOrder)
  }

  // 離開詳情時只停止唯讀查詢，保留同一訂單的已知付款資訊。
  function leaveDetail(): void {
    detailRead?.abort()
  }

  // 建單或重送已確認時直接採用回應；同一張訂單仍須核對已知付款。
  function acceptCreated(order: OrderDetail): void {
    // 驗證在清除待確認本文之前完成，免得異常回應遺失可重送的原內容。
    if (
      selectedOrder.value === order.id
      && selectedPaymentId
      && order.payment?.id !== selectedPaymentId
    ) throw new Error('建單重送回應與已知付款識別不符。')

    // 停止早先的唯讀請求，避免蓋掉剛確認的訂單。
    leaveDetail()
    selectedOrder.value = order.id
    selectedPaymentId = order.payment?.id
    detail.value = order
    detailError.value = ''
    cancelResult.value = null
    paymentResult.value = null
  }

  // 只有目前詳情仍為待付款才送出取消，並回讀同一筆訂單確認目前狀態。
  async function requestCancel(): Promise<void> {
    // 只接受目前選取且符合狀態契約的訂單。
    const order = detail.value
    if (scope.writeBusy() || order?.status !== 'awaiting_payment') return
    cancelling.value = true
    cancelResult.value = null

    try {
      try {
        // 驗證取消成功與所選單號一致後，仍取得最新詳情及庫存。
        await cancelOrder(order.id, scope.storeId, scope.csrfToken(), scope.signal)
        if (scope.signal.aborted) return
        cancelResult.value = 'cancelled'
      } catch (error) {
        // 會話重新確認或離開門市時，由新畫面重新取得授權資料。
        if (scope.signal.aborted) return
        if (needsSession(error)) {
          scope.sessionExpired()
          return
        }
        // 狀態衝突可能已觸發到期釋放；其他無法驗證的回應維持待確認。
        if (error instanceof ApiError && error.status === 409 && error.code === 'ORDER_STATE_CONFLICT') {
          cancelResult.value = 'conflict'
        } else if (error instanceof ApiError && error.status === 404 && error.code === 'NOT_FOUND') {
          cancelResult.value = 'not-found'
        } else {
          cancelResult.value = 'unknown'
        }
      }

      // 成功、狀態衝突或不明回應都用回讀更新現有清單與庫存。
      scope.stockChanged()
      void focusDetail(order.id)
      await readDetail(order.id)
    } finally {
      cancelling.value = false
    }
  }

  // 僅由明確操作發起或查詢付款；任何不明回應都回讀原訂單而不重送。
  async function requestPayment(action: PaymentAction): Promise<void> {
    // 只接受目前選取且符合狀態契約的訂單。
    const order = detail.value
    if (scope.writeBusy() || !order) return
    if (action === 'start' && order.status !== 'awaiting_payment') return
    if (action === 'query' && !['processing', 'paid', 'failed'].includes(order.status)) return

    paymentAction.value = action
    paymentResult.value = null
    try {
      try {
        // 每個付款操作只送出一次；正常回應須通過 API 層的訂單及付款驗證。
        const updated = action === 'start'
          ? await startPayment(order.id, scope.storeId, scope.csrfToken(), scope.signal)
          : await queryPayment(order.id, scope.storeId, scope.csrfToken(), scope.signal)
        if (scope.signal.aborted) return
        if (selectedOrder.value === order.id) {
          if (selectedPaymentId && updated.payment?.id !== selectedPaymentId) {
            throw new Error('付款操作回應更換了付款識別。')
          }
          selectedPaymentId = updated.payment?.id
          detail.value = updated
          detailError.value = ''
          paymentResult.value = 'confirmed'
        }
      } catch (error) {
        // 舊會話不再擁有畫面；其餘拒絕或不明結果都先安全回讀。
        if (scope.signal.aborted) return
        if (needsSession(error)) {
          scope.sessionExpired()
          return
        }
        if (error instanceof ApiError && error.status === 404 && error.code === 'NOT_FOUND') {
          paymentResult.value = 'not-found'
        } else if (error instanceof ApiError && error.status === 409 && error.code === 'ORDER_STATE_CONFLICT') {
          paymentResult.value = 'conflict'
        } else {
          paymentResult.value = 'unknown'
        }
      }

      // 先等不明回應的詳情回讀，再刷新清單與庫存，避免讀到較早的狀態。
      if (selectedOrder.value === order.id && paymentResult.value !== 'confirmed') {
        await readDetail(order.id)
      }
      if (scope.signal.aborted) return
      scope.stockChanged()
      if (selectedOrder.value === order.id) void focusDetail(order.id)
    } finally {
      paymentAction.value = null
    }
  }

  onBeforeUnmount(leaveDetail)

  return {
    selectedOrder, detail, detailError, paymentAction, cancelling, cancelFeedback, paymentFeedback,
    viewOrder, leaveDetail, acceptCreated, readDetail, requestCancel, requestPayment,
  }
}
