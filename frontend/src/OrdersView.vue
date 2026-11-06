<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useTemplateRef, watch } from 'vue'
import {
  ApiError,
  cancelOrder,
  createOrder,
  queryPayment,
  getOrder,
  getOrders,
  isPositiveSafeInteger,
  readOrderDraft,
  startPayment,
  type InventoryItem,
  type OrderDetail,
  type OrderDraft,
  type OrderFilters,
  type OrderPage,
  type PaymentAnomaly,
  type PendingPaymentReason,
  type OrderStatus,
  type Store,
} from './api'

const props = defineProps<{ store: Store; actorId: number; csrfToken: string; products: InventoryItem[] }>()
const emit = defineEmits<{ sessionExpired: []; stockChanged: [] }>()

type DraftLine = { productId: number; name?: string; quantity: string }
type PendingOrder = { body: string; draft: OrderDraft }
type CancelResult = 'cancelled' | 'conflict' | 'unknown' | 'not-found'
type PaymentAction = 'start' | 'query'
type PaymentResult = 'confirmed' | 'conflict' | 'unknown' | 'not-found'

// 元件依門市與登入帳號重建，請求及待確認操作都使用該次掛載的範圍。
const storeId = props.store.id
const actorId = props.actorId
const lifetime = new AbortController()
const storageKey = `store-ordering:pending-order:${actorId}:${storeId}`
const lines = ref<DraftLine[]>([])
const productId = ref<number | null>(null)
const pendingOrder = shallowRef<PendingOrder | null>(null)
const storageBlocked = ref(false)
const creating = ref(false)
const createFeedback = ref('')

const filterFields = ref(emptyFilters())
const filters = shallowRef(emptyFilters())
const filterError = ref('')
const page = ref(1)
const lastPage = ref(1)
const list = shallowRef<OrderPage | null>(null)
const listError = ref('')
const listRefresh = ref(0)

const selectedOrder = ref<number | null>(null)
const detail = shallowRef<OrderDetail | null>(null)
const detailError = ref('')
const detailHeading = useTemplateRef<HTMLHeadingElement>('detailHeading')
const cancelling = ref(false)
const cancelResult = ref<CancelResult | null>(null)
const paymentAction = ref<PaymentAction | null>(null)
const paymentResult = ref<PaymentResult | null>(null)
let detailRead: AbortController | undefined
// 付款識別屬於目前帳號、門市及選取的訂單，詳情讀取失敗時仍保留。
let selectedPaymentId: string | undefined

const statusLabels: Record<OrderStatus, string> = {
  awaiting_payment: '待付款',
  processing: '付款處理中',
  paid: '已付款',
  failed: '付款失敗',
  cancelled: '已取消',
  expired: '已到期',
}
const paymentStatusLabels = { pending: '待確認', paid: '已付款', failed: '付款失敗' }
const pendingReasonLabels: Record<Exclude<PendingPaymentReason, null>, string> = {
  creation_unconfirmed: '付款建立尚未確認',
  awaiting_result: '等待模擬付款結果',
  service_unavailable: '模擬付款服務暫時無法使用',
  payment_not_found: '尚未查到遠端付款',
  invalid_response: '付款回應內容待確認',
  recovery_exhausted: '自動補辦已達上限，請查詢付款結果。',
}
const anomalyLabels: Record<PaymentAnomaly['reason'], string> = {
  invalid_response: '付款回應無效',
  payment_id_mismatch: '付款識別不符',
  order_id_mismatch: '訂單識別不符',
  amount_mismatch: '付款金額不符',
  currency_mismatch: '付款幣別不符',
  terminal_conflict: '付款終局狀態衝突',
}
const numberFormat = new Intl.NumberFormat('zh-TW')
const dateFormat = new Intl.DateTimeFormat('zh-TW', {
  timeZone: 'Asia/Taipei',
  dateStyle: 'medium',
  timeStyle: 'medium',
  hourCycle: 'h23',
})

// 只列出庫存目前頁可新增的商品，草稿可保留其他頁已選的品項。
const selectableProducts = computed(() => {
  const selected = new Set(lines.value.map((line) => line.productId))
  return props.products.filter((product) => !selected.has(product.id))
})
const selectedProduct = computed(() => selectableProducts.value.find((product) => product.id === productId.value))

// 同時只執行一筆寫入，避免建單與取消互相取代目前詳情。
const writeBusy = computed(() => creating.value || cancelling.value || paymentAction.value !== null)

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
    return '已確認付款仍在處理中，庫存持續保留。'
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

// 以同一組空值初始化或清除已套用的篩選條件。
function emptyFilters(): OrderFilters {
  return { orderNumber: '', status: '', from: '', to: '' }
}

// 只在會話與防偽驗證失效時交由入口重新取得身分及 token。
function needsSession(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.status === 419)
}

// 將原始本文帶回畫面；未驗證的資料會鎖住建立功能以免重複開單。
function restorePending(): void {
  try {
    const body = sessionStorage.getItem(storageKey)
    if (body !== null) {
      const draft = readOrderDraft(body, storeId)
      pendingOrder.value = { body, draft }
      // 商品名稱可從目前庫存取得，持久化內容只保存實際送出的欄位。
      lines.value = draft.items.map(({ productId, quantity }) => ({ productId, quantity: String(quantity) }))
    } else {
      pendingOrder.value = null
    }
    storageBlocked.value = false
  } catch {
    storageBlocked.value = true
  }
}

// 只移除這次已確定的本文，其他嘗試的復原資料仍屬於原嘗試。
function clearPending(attempt: PendingOrder): boolean {
  try {
    const saved = sessionStorage.getItem(storageKey)
    if (saved === attempt.body) sessionStorage.removeItem(storageKey)
    else if (saved !== null) {
      restorePending()
      return false
    }
    pendingOrder.value = null
    return true
  } catch {
    return false
  }
}

// 以原草稿名稱或目前庫存標示品項，復原時也能顯示跨頁的商品識別。
function lineName(line: DraftLine): string {
  return line.name ?? props.products.find((product) => product.id === line.productId)?.name ?? `商品 #${line.productId}`
}

// 從目前授權庫存加入品項，數量由草稿列中的原生欄位調整。
function addLine(): void {
  if (pendingOrder.value || lines.value.length >= 100) return
  const product = selectedProduct.value
  if (!product) return
  lines.value.push({ productId: product.id, name: product.name, quantity: '1' })
  productId.value = null
}

// 只接受 API 明定的業務拒絕，未知結果則保留原操作。
function rejectedCreate(error: unknown): string | undefined {
  if (!(error instanceof ApiError)) return
  if (error.status === 409 && error.code === 'INSUFFICIENT_STOCK') return '可售庫存不足，請調整品項或數量。'
  if (error.status === 409 && error.code === 'OPERATION_KEY_CONFLICT') return '操作鍵已用於內容不同的訂單，請先確認訂單清單。'
  if (error.status === 422 && error.code === 'VALIDATION_FAILED') return '品項、數量或金額超出允許範圍，請修正後再試。'
  if (error.status === 413 && error.code === 'PAYLOAD_TOO_LARGE') return '訂單內容超過請求大小限制。'
  if (error.status === 404 && error.code === 'NOT_FOUND') return '找不到可建立訂單的門市，請重新確認門市。'
}

// 第一次送出前保存本文；只由使用者明確操作，才用同一份本文再次確認。
async function submitOrder(): Promise<void> {
  if (writeBusy.value || storageBlocked.value) return
  createFeedback.value = ''
  let attempt = pendingOrder.value
  if (!attempt) {
    if (lines.value.length === 0 || lines.value.length > 100) return

    // 從欄位原始文字核對正整數，避免小數或超界數值被轉成可提交的整數。
    const items = []
    for (const line of lines.value) {
      const quantity = Number(line.quantity)
      if (!/^[0-9]+$/.test(line.quantity) || !isPositiveSafeInteger(quantity)) {
        createFeedback.value = `${lineName(line)}的數量必須為 1 到 9,007,199,254,740,991 的整數。`
        return
      }
      items.push({ productId: line.productId, quantity })
    }

    try {
      // 若瀏覽器已有未決本文，顯示該筆操作並等待使用者再次確認。
      if (sessionStorage.getItem(storageKey) !== null) {
        restorePending()
        return
      }
      const draft = { storeId, operationKey: crypto.randomUUID(), items }
      const body = JSON.stringify(draft)
      sessionStorage.setItem(storageKey, body)
      attempt = { body, draft }
      pendingOrder.value = attempt
    } catch {
      createFeedback.value = '無法保存待確認的訂單資料，訂單尚未送出。'
      return
    }
  }

  creating.value = true
  try {
    // 確認帳號、門市及品項後才解除草稿鎖定，並回讀清單與庫存。
    const order = await createOrder(attempt.body, attempt.draft, actorId, props.csrfToken, lifetime.signal)
    if (lifetime.signal.aborted) return
    // 確認同一張訂單時，已知的付款識別必須與重送回應一致。
    if (
      selectedOrder.value === order.id
      && selectedPaymentId
      && order.payment?.id !== selectedPaymentId
    ) throw new Error('建單重送回應與已知付款識別不符。')
    if (clearPending(attempt)) {
      lines.value = []
      productId.value = null
      createFeedback.value = `已確認訂單 #${order.id}。`
    } else {
      createFeedback.value = `已確認訂單 #${order.id}。瀏覽器仍保留待確認紀錄。`
    }
    // 建單確認後改顯示該筆詳情，清除前次操作的回饋。
    detailRead?.abort()
    selectedOrder.value = order.id
    selectedPaymentId = order.payment?.id
    detail.value = order
    detailError.value = ''
    cancelResult.value = null
    paymentResult.value = null
    listRefresh.value++
    emit('stockChanged')
    void focusDetail(order.id)
  } catch (error) {
    if (lifetime.signal.aborted) return
    // 更新會話期間保留原始本文，避免重新登入後建立另一筆訂單。
    if (needsSession(error)) {
      emit('sessionExpired')
      return
    }

    const rejection = rejectedCreate(error)
    if (rejection) {
      createFeedback.value = clearPending(attempt) ? rejection : `${rejection} 瀏覽器仍保留待確認紀錄。`
      if (error instanceof ApiError && error.code === 'INSUFFICIENT_STOCK') emit('stockChanged')
    } else {
      createFeedback.value = '建立結果尚未確認。請使用原內容確認同一筆訂單。'
    }
  } finally {
    creating.value = false
  }
}

// 篩選只在提交時生效，換條件後從第一頁開始讀取。
function applyFilters(): void {
  const id = filterFields.value.orderNumber
  if (id !== '' && (!/^[1-9][0-9]*$/.test(id) || !isPositiveSafeInteger(Number(id)))) {
    filterError.value = '請輸入有效的正整數訂單編號。'
    return
  }
  filterError.value = ''
  page.value = 1
  filters.value = { ...filterFields.value }
}

// 同步清空輸入及已套用條件，以免翻頁繼續使用舊篩選。
function resetFilters(): void {
  filterFields.value = emptyFilters()
  applyFilters()
}

// 取消已被篩選、換頁或重查取代的清單，避免顯示較晚完成的舊結果。
watch([filters, page, listRefresh], async ([query, currentPage], [previousQuery], onCleanup) => {
  const controller = new AbortController()
  onCleanup(() => controller.abort())
  list.value = null
  listError.value = ''
  if (query !== previousQuery) lastPage.value = 1

  try {
    // 只用目前請求的總筆數更新分頁控制。
    const result = await getOrders(storeId, query, currentPage, controller.signal)
    if (controller.signal.aborted) return
    lastPage.value = Math.max(1, Math.ceil(result.meta.total / result.meta.perPage))
    if (currentPage > lastPage.value) {
      page.value = lastPage.value
      return
    }
    list.value = result
  } catch (error) {
    if (controller.signal.aborted) return
    if (needsSession(error)) emit('sessionExpired')
    else listError.value = '無法讀取訂單清單，請重試。'
  }
}, { immediate: true })

// 詳情獨立取消過期讀取，並保留已知付款識別以核對同一筆交易。
async function readDetail(id: number, manualRetry = false): Promise<void> {
  detailRead?.abort()
  const controller = new AbortController()
  detailRead = controller
  if (selectedOrder.value !== id) selectedPaymentId = undefined
  selectedOrder.value = id
  detail.value = null
  detailError.value = ''

  try {
    // 切換訂單、門市或帳號會中止舊讀取，只接受仍屬於目前詳情的結果。
    const order = await getOrder(id, storeId, controller.signal)
    if (controller.signal.aborted) return
    if (selectedPaymentId && order.payment?.id !== selectedPaymentId) throw new Error('付款識別與先前讀取不符。')
    selectedPaymentId = order.payment?.id
    detail.value = order

    // 人工重試成功時，先前不明的付款可能已結算，連同清單與庫存一起更新。
    if (manualRetry && paymentResult.value === 'unknown') {
      listRefresh.value++
      emit('stockChanged')
    }
  } catch (error) {
    if (controller.signal.aborted) return
    if (needsSession(error)) emit('sessionExpired')
    else if (error instanceof ApiError && error.status === 404 && error.code === 'NOT_FOUND') {
      detailError.value = '找不到這筆訂單。'
    } else {
      detailError.value = '無法讀取訂單詳情，請重試。'
    }
  }
}

// 從清單開啟詳情時，清除先前的取消及付款回饋。
function viewOrder(id: number): void {
  if (writeBusy.value) return
  cancelResult.value = null
  paymentResult.value = null
  void readDetail(id)
  void focusDetail(id)
}

// 開啟詳情或移除操作按鈕後，把鍵盤焦點移至目前訂單標題。
async function focusDetail(id: number): Promise<void> {
  await nextTick()
  if (!lifetime.signal.aborted && selectedOrder.value === id) detailHeading.value?.focus()
}

// 只有目前詳情仍為待付款才送出取消，並回讀同一筆訂單確認目前狀態。
async function requestCancel(): Promise<void> {
  const order = detail.value
  if (writeBusy.value || order?.status !== 'awaiting_payment') return
  cancelling.value = true
  cancelResult.value = null

  try {
    try {
      // 驗證取消成功與所選單號一致後，仍取得最新詳情及庫存。
      await cancelOrder(order.id, storeId, props.csrfToken, lifetime.signal)
      if (lifetime.signal.aborted) return
      cancelResult.value = 'cancelled'
    } catch (error) {
      // 會話重新確認或離開門市時，由新畫面重新取得授權資料。
      if (lifetime.signal.aborted) return
      if (needsSession(error)) {
        emit('sessionExpired')
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
    listRefresh.value++
    emit('stockChanged')
    void focusDetail(order.id)
    await readDetail(order.id)
  } finally {
    cancelling.value = false
  }
}

// 僅由明確操作發起或查詢付款；任何不明回應都回讀原訂單而不重送。
async function requestPayment(action: PaymentAction): Promise<void> {
  const order = detail.value
  if (writeBusy.value || !order) return
  if (action === 'start' && order.status !== 'awaiting_payment') return
  if (action === 'query' && !['processing', 'paid', 'failed'].includes(order.status)) return

  paymentAction.value = action
  paymentResult.value = null
  try {
    try {
      // 每個付款操作只送出一次；正常回應須通過 API 層的訂單及付款驗證。
      const updated = action === 'start'
        ? await startPayment(order.id, storeId, props.csrfToken, lifetime.signal)
        : await queryPayment(order.id, storeId, props.csrfToken, lifetime.signal)
      if (lifetime.signal.aborted) return
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
      if (lifetime.signal.aborted) return
      if (needsSession(error)) {
        emit('sessionExpired')
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
    if (lifetime.signal.aborted) return
    listRefresh.value++
    emit('stockChanged')
    if (selectedOrder.value === order.id) void focusDetail(order.id)
  } finally {
    paymentAction.value = null
  }
}

// 讀取瀏覽器此分頁保留的操作；離開帳號或門市即停止更新舊畫面。
onMounted(restorePending)
onBeforeUnmount(() => {
  lifetime.abort()
  detailRead?.abort()
})
</script>

<template>
  <section class="panel order-panel" aria-labelledby="orders-heading">
    <h2 id="orders-heading">門市訂單</h2>

    <section class="order-section" aria-labelledby="create-order-heading">
      <h3 id="create-order-heading">建立訂單</h3>
      <p v-if="createFeedback" role="status">{{ createFeedback }}</p>
      <div v-if="storageBlocked" class="retry">
        <p role="alert">無法讀取待確認訂單，目前無法建立新訂單。</p>
        <button type="button" @click="restorePending">重新讀取待確認訂單</button>
      </div>
      <form class="order-form" @submit.prevent="submitOrder">
        <fieldset :disabled="!!pendingOrder || storageBlocked || writeBusy">
          <legend>訂購品項（最多 100 項）</legend>
          <p>可從上方目前頁面的庫存選擇商品。換庫存頁可繼續新增品項。</p>
          <label for="order-product">商品</label>
          <select id="order-product" v-model="productId" :disabled="selectableProducts.length === 0 || lines.length >= 100">
            <option :value="null" disabled>選擇商品</option>
            <option v-for="product in selectableProducts" :key="product.id" :value="product.id">
              {{ product.name }}
            </option>
          </select>
          <button
            class="secondary"
            type="button"
            :disabled="!selectedProduct || lines.length >= 100"
            @click="addLine"
          >新增品項</button>

          <div v-for="(line, index) in lines" :key="line.productId" class="order-line">
            <label :for="`order-quantity-${line.productId}`">數量（{{ lineName(line) }}）</label>
            <input
              :id="`order-quantity-${line.productId}`"
              v-model="line.quantity"
              type="text"
              inputmode="numeric"
              pattern="[0-9]+"
              required
            >
            <button
              class="secondary"
              type="button"
              :aria-label="`移除品項（${lineName(line)}）`"
              @click="lines.splice(index, 1)"
            >移除品項</button>
          </div>
        </fieldset>

        <p v-if="creating" role="status">正在確認訂單…</p>
        <template v-else-if="pendingOrder">
          <p role="status">此門市有一筆待確認的訂單。確認會使用相同品項、數量及操作鍵。</p>
          <button type="button" :disabled="storageBlocked || writeBusy" @click="submitOrder">確認同一筆訂單</button>
        </template>
        <button v-else type="submit" :disabled="lines.length === 0 || storageBlocked || writeBusy">建立訂單</button>
      </form>
    </section>

    <section class="order-section" aria-labelledby="order-list-heading">
      <h3 id="order-list-heading">訂單清單</h3>
      <p>日期篩選以台北時間為準。</p>
      <form class="order-form" @submit.prevent="applyFilters">
        <label for="order-number">訂單編號</label>
        <input id="order-number" v-model="filterFields.orderNumber" inputmode="numeric" pattern="[1-9][0-9]*">
        <label for="order-status">訂單狀態</label>
        <select id="order-status" v-model="filterFields.status">
          <option value="">全部狀態</option>
          <option v-for="(label, status) in statusLabels" :key="status" :value="status">{{ label }}</option>
        </select>
        <label for="order-from">建立起日（含）</label>
        <input id="order-from" v-model="filterFields.from" type="date" min="0001-01-01" max="9999-12-31">
        <label for="order-to">建立迄日（不含）</label>
        <input id="order-to" v-model="filterFields.to" type="date" min="0001-01-01" max="9999-12-31">
        <p v-if="filterError" role="alert">{{ filterError }}</p>
        <div class="order-actions">
          <button type="submit">套用篩選</button>
          <button class="secondary" type="button" @click="resetFilters">清除篩選</button>
        </div>
      </form>

      <div v-if="listError" class="retry">
        <p role="alert">{{ listError }}</p>
        <button type="button" @click="listRefresh++">重新讀取訂單清單</button>
      </div>
      <p v-else-if="!list" role="status">正在讀取訂單清單…</p>
      <p v-else-if="list.data.length === 0" role="status">找不到符合條件的訂單。</p>
      <ul v-else class="order-list" aria-label="訂單查詢結果">
        <li v-for="order in list.data" :key="order.id">
          <button class="secondary" type="button" :disabled="writeBusy" @click="viewOrder(order.id)">
            查看訂單 #{{ order.id }}
          </button>
          <p>{{ statusLabels[order.status] }} · {{ numberFormat.format(order.total) }} 元</p>
          <p>建立時間 {{ dateFormat.format(new Date(order.createdAt)) }}</p>
        </li>
      </ul>
      <nav v-if="lastPage > 1" class="pagination" aria-label="訂單分頁">
        <button class="secondary" type="button" :disabled="page === 1" @click="page--">上一頁</button>
        <span>{{ page }} / {{ lastPage }} 頁</span>
        <button class="secondary" type="button" :disabled="page >= lastPage" @click="page++">下一頁</button>
      </nav>
    </section>

    <section v-if="selectedOrder !== null" class="order-section" aria-labelledby="order-detail-heading">
      <h3 id="order-detail-heading" ref="detailHeading" tabindex="-1">訂單 #{{ selectedOrder }}</h3>
      <p v-if="cancelFeedback" role="status">{{ cancelFeedback }}</p>
      <p v-if="paymentFeedback" role="status">{{ paymentFeedback }}</p>
      <p v-if="paymentAction" role="status">
        {{ paymentAction === 'start' ? '正在發起付款…' : '正在查詢付款結果…' }}
      </p>
      <div v-if="detailError" class="retry">
        <p role="alert">{{ detailError }}</p>
        <button type="button" :disabled="writeBusy" @click="readDetail(selectedOrder, true)">重新讀取訂單詳情</button>
      </div>
      <p v-else-if="!detail" role="status">正在讀取訂單詳情…</p>
      <template v-else>
        <dl class="order-summary">
          <dt>門市</dt><dd>{{ props.store.name }}</dd>
          <dt>狀態</dt><dd>{{ statusLabels[detail.status] }}</dd>
          <dt>總額</dt><dd>{{ numberFormat.format(detail.total) }} 元</dd>
          <dt>建立人編號</dt><dd>{{ detail.createdBy }}</dd>
          <dt>建立時間</dt><dd>{{ dateFormat.format(new Date(detail.createdAt)) }}</dd>
          <dt>到期時間</dt><dd>{{ dateFormat.format(new Date(detail.expiresAt)) }}</dd>
          <dt>付款識別</dt>
          <dd v-if="detail.payment" class="payment-id">{{ detail.payment.id }}</dd>
          <dd v-else>尚未建立付款</dd>
          <template v-if="detail.payment">
            <dt>付款狀態</dt><dd>{{ paymentStatusLabels[detail.payment.status] }}</dd>
            <dt v-if="detail.payment.pendingReason">待處理原因</dt>
            <dd v-if="detail.payment.pendingReason">{{ pendingReasonLabels[detail.payment.pendingReason] }}</dd>
          </template>
        </dl>
        <h4>訂購明細</h4>
        <ul>
          <li v-for="item in detail.items" :key="item.productId">
            {{ item.name }} × {{ numberFormat.format(item.quantity) }}（單價
            {{ numberFormat.format(item.unitPrice) }} 元，小計 {{ numberFormat.format(item.subtotal) }} 元）
          </li>
        </ul>
        <h4>狀態歷程</h4>
        <ol>
          <li v-for="(event, index) in detail.history" :key="index">
            {{ statusLabels[event.status] }} · {{ dateFormat.format(new Date(event.occurredAt)) }}
          </li>
        </ol>
        <h4>庫存異動紀錄</h4>
        <ol>
          <li v-for="(entry, index) in detail.stockLedger" :key="index">
            {{ entry.type === 'reserved' ? '保留' : entry.type === 'released' ? '釋放' : '扣除' }}
            {{ detail.items.find((item) => item.productId === entry.productId)?.name ?? `商品 #${entry.productId}` }}
            × {{ numberFormat.format(entry.quantity) }} · {{ dateFormat.format(new Date(entry.occurredAt)) }}
          </li>
        </ol>
        <h4>付款異常紀錄</h4>
        <ol v-if="detail.anomalies.length">
          <li v-for="(anomaly, index) in detail.anomalies" :key="index">
            {{ anomalyLabels[anomaly.reason] }} · {{ dateFormat.format(new Date(anomaly.occurredAt)) }}
          </li>
        </ol>
        <p v-else>無付款異常紀錄。</p>
        <button
          v-if="detail.status === 'awaiting_payment'"
          class="secondary"
          type="button"
          :disabled="writeBusy"
          @click="requestCancel"
        >{{ cancelling ? '取消確認中…' : '取消訂單' }}</button>
        <button
          v-if="detail.status === 'awaiting_payment'"
          type="button"
          :disabled="writeBusy"
          @click="requestPayment('start')"
        >{{ paymentAction === 'start' ? '付款發起中…' : '發起付款' }}</button>
        <button
          v-else-if="['processing', 'paid', 'failed'].includes(detail.status)"
          type="button"
          :disabled="writeBusy"
          @click="requestPayment('query')"
        >{{ paymentAction === 'query' ? '查詢中…' : '查詢付款結果' }}</button>
      </template>
    </section>
  </section>
</template>
