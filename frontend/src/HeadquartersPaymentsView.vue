<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, useTemplateRef, watch } from 'vue'
import {
  ApiError,
  decideMockPayment,
  dispatchMockPayment,
  getMockPayments,
  type MockPayment,
  type MockPaymentPage,
  type PaymentDispatchMode,
} from './api'
import { needsSession } from './workspace'

const props = defineProps<{ csrfToken: string; active: boolean }>()
const emit = defineEmits<{ sessionExpired: []; busy: [active: boolean] }>()

const perPage = 50
const page = ref(1)
const lastPage = ref(1)
const payments = shallowRef<MockPaymentPage | null>(null)
const loading = ref(false)
const loadError = ref('')
const actionPaymentId = ref<string | null>(null)
const actionMode = ref<PaymentDispatchMode | null>(null)
const feedback = ref('')
const feedbackElement = useTemplateRef<HTMLParagraphElement>('feedbackElement')
const pendingReadback = shallowRef<{ paymentId: string; status: 'paid' | 'failed' } | null>(null)
const lifetime = new AbortController()
let listRead: AbortController | undefined

const statusLabels = { pending: '待決定', paid: '已付款', failed: '付款失敗' }
const numberFormat = new Intl.NumberFormat('zh-TW')
const busy = computed(() => actionPaymentId.value !== null)

// 決定與派送未完成時，外層導覽需保留此畫面及請求。
watch(busy, (active) => emit('busy', active), { flush: 'sync' })

// 安全重讀目前有限頁；決定回應不明時只用 GET 核對，不再次送出決定。
async function readPage(requestedPage = page.value): Promise<void> {
  listRead?.abort()
  const controller = new AbortController()
  listRead = controller
  payments.value = null
  loadError.value = ''
  loading.value = true

  try {
    // 所有清單讀取都受面板及請求生命週期限制。
    let result = await getMockPayments(requestedPage, controller.signal, perPage)
    if (controller.signal.aborted || lifetime.signal.aborted) return
    let visiblePage = requestedPage
    let pages = Math.max(1, Math.ceil(result.meta.total / result.meta.perPage))
    if (requestedPage > pages) {
      page.value = pages
      await readPage(pages)
      return
    }

    // 新付款可能推動剛決定的項目到下一頁；最多再讀取一頁完成安全核對。
    const target = pendingReadback.value
    if (
      target
      && !result.data.some((payment) => payment.paymentId === target.paymentId)
      && requestedPage < pages
    ) {
      const next = await getMockPayments(requestedPage + 1, controller.signal, perPage)
      if (controller.signal.aborted || lifetime.signal.aborted) return
      if (next.data.some((payment) => payment.paymentId === target.paymentId)) {
        result = next
        visiblePage = requestedPage + 1
        page.value = visiblePage
        pages = Math.max(1, Math.ceil(next.meta.total / next.meta.perPage))
      }
    }

    if (controller.signal.aborted || lifetime.signal.aborted) return
    payments.value = result
    page.value = visiblePage
    lastPage.value = pages

    // 只清除已由讀回資料找到的決定，找不到時保留手動重查線索。
    if (target) {
      const current = result.data.find((payment) => payment.paymentId === target.paymentId)
      if (current) {
        if (current.status === target.status) {
          feedback.value = `已重新讀取付款狀態：${statusLabels[current.status]}。`
        } else if (current.status === 'pending') {
          feedback.value = '決定結果尚未確認；付款仍待決定。請核對目前狀態後再手動操作。'
        } else {
          feedback.value = `已重新讀取付款狀態：${statusLabels[current.status]}。付款終局不可反轉。`
        }
        pendingReadback.value = null
      } else {
        feedback.value = `已重新讀取第 ${visiblePage} 頁，尚未找到該付款。請翻頁確認；不要重送尚未核實的決定。`
      }
    }
  } catch (error) {
    if (controller.signal.aborted || lifetime.signal.aborted) return
    if (needsSession(error)) emit('sessionExpired')
    else {
      loadError.value = '無法讀取模擬付款清單，請重試。'
      if (pendingReadback.value) feedback.value = '決定結果尚未確認。請重新載入清單核對，不要重送決定。'
    }
  } finally {
    if (!controller.signal.aborted) loading.value = false
  }
}

// 只允許目前清單仍為待決定的付款被人工決定一次。
async function decide(payment: MockPayment, status: 'paid' | 'failed'): Promise<void> {
  if (busy.value || payment.status !== 'pending') return
  actionPaymentId.value = payment.paymentId
  feedback.value = ''
  pendingReadback.value = null
  try {
    try {
      // 決定只送一次；成功、衝突、拒絕或未知結果都改以清單回讀。
      await decideMockPayment(payment.paymentId, status, props.csrfToken, lifetime.signal)
      if (lifetime.signal.aborted) return
    } catch (error) {
      if (lifetime.signal.aborted) return
      if (needsSession(error)) {
        emit('sessionExpired')
        return
      }
    }

    pendingReadback.value = { paymentId: payment.paymentId, status }
    await readPage(page.value)
    if (!lifetime.signal.aborted) {
      await nextTick()
      feedbackElement.value?.focus()
    }
  } finally {
    actionPaymentId.value = null
  }
}

// 終局付款可明確排入原通知事件；不明回應不自動重送。
async function dispatch(payment: MockPayment, mode: PaymentDispatchMode): Promise<void> {
  if (busy.value || payment.status === 'pending') return
  actionPaymentId.value = payment.paymentId
  actionMode.value = mode
  feedback.value = ''
  try {
    // 只有通過 202 與事件識別核對後，才回報工作已排入佇列。
    const result = await dispatchMockPayment(payment.paymentId, mode, props.csrfToken, lifetime.signal)
    if (lifetime.signal.aborted) return
    feedback.value = mode === 'immediate'
      ? `通知事件 ${result.eventId} 已排入立即派送佇列。`
      : `通知事件 ${result.eventId} 已排入 60 秒後派送佇列。`
  } catch (error) {
    // 只有明確拒絕可確認未排入；其他失敗保留可能已寫入的未知結果。
    if (lifetime.signal.aborted) return
    if (needsSession(error)) {
      emit('sessionExpired')
      return
    }
    if (error instanceof ApiError && error.status === 404) {
      feedback.value = '找不到付款或通知事件，請重新載入付款清單。'
    } else if (error instanceof ApiError && error.status === 409) {
      feedback.value = '付款或通知事件狀態已變更，請重新載入付款清單。'
    } else if (error instanceof ApiError && error.status < 500) {
      feedback.value = '通知派送未排入，請確認付款狀態後再操作。'
    } else {
      feedback.value = '通知派送結果尚未確認，可能已排入佇列。請確認目前付款狀態後，再手動重送。'
    }
  } finally {
    actionPaymentId.value = null
    actionMode.value = null
  }
  // 以畫面回饋接收焦點，讓鍵盤使用者也能得知派送結果。
  if (!lifetime.signal.aborted) {
    await nextTick()
    feedbackElement.value?.focus()
  }
}

// 換頁沿用同一讀取入口，讓舊頁結果不能覆蓋新頁。
function changePage(nextPage: number): void {
  if (busy.value || loading.value || nextPage < 1 || nextPage > lastPage.value) return
  page.value = nextPage
  void readPage(nextPage)
}

// 總部面板卸載後不再允許舊請求更新新會話畫面。
onBeforeUnmount(() => {
  lifetime.abort()
  listRead?.abort()
})

// 每次回到面板都讀取目前付款頁；離開時取消不再顯示的唯讀請求。
watch(() => props.active, (active) => {
  if (active) void readPage()
  else listRead?.abort()
}, { immediate: true })
</script>

<template>
  <section class="payment-panel" aria-labelledby="payments-heading">
    <header class="heading">
      <h1 id="payments-heading" tabindex="-1">總部模擬付款</h1>
      <button class="secondary" type="button" :disabled="loading || busy" @click="readPage()">重新載入付款</button>
    </header>
    <p v-if="feedback" ref="feedbackElement" tabindex="-1" role="status">{{ feedback }}</p>
    <p v-if="busy" role="status">{{ actionMode ? '正在排入通知派送…' : '正在決定模擬付款…' }}</p>
    <div v-if="loadError" class="retry">
      <p role="alert">{{ loadError }}</p>
      <button type="button" :disabled="busy" @click="readPage()">重試</button>
    </div>
    <p v-else-if="loading" role="status">正在讀取模擬付款…</p>
    <p v-else-if="payments?.data.length === 0" role="status">目前沒有模擬付款。</p>
    <div v-else-if="payments" class="table-wrap" tabindex="0" role="region" aria-label="模擬付款表格">
      <table class="payments-table" aria-label="總部模擬付款清單">
        <thead>
          <tr>
            <th scope="col">付款識別</th>
            <th scope="col">訂單</th>
            <th scope="col" class="num">金額</th>
            <th scope="col">狀態</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="payment in payments.data" :key="payment.paymentId">
            <th scope="row" class="payment-id">{{ payment.paymentId }}</th>
            <td>#{{ payment.orderId }}</td>
            <td class="num">{{ payment.currency }} {{ numberFormat.format(payment.amount) }}</td>
            <td><span class="status" :class="payment.status">{{ statusLabels[payment.status] }}</span></td>
            <td>
              <div v-if="payment.status === 'pending'" class="actions">
                <button
                  type="button"
                  :disabled="busy || loading"
                  @click="decide(payment, 'paid')"
                >{{ actionPaymentId === payment.paymentId ? '決定中…' : '決定成功' }}</button>
                <button
                  class="secondary"
                  type="button"
                  :disabled="busy || loading"
                  @click="decide(payment, 'failed')"
                >{{ actionPaymentId === payment.paymentId ? '決定中…' : '決定失敗' }}</button>
              </div>
              <div v-else class="actions">
                <button
                  type="button"
                  :disabled="busy || loading"
                  @click="dispatch(payment, 'immediate')"
                >{{ actionPaymentId === payment.paymentId && actionMode === 'immediate' ? '排入中…' : '立即送出／重送' }}</button>
                <button
                  class="secondary"
                  type="button"
                  :disabled="busy || loading"
                  @click="dispatch(payment, 'delayed')"
                >{{ actionPaymentId === payment.paymentId && actionMode === 'delayed' ? '排入中…' : '延遲 60 秒送出' }}</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav v-if="lastPage > 1" class="pagination" aria-label="模擬付款分頁">
      <button class="secondary" type="button" :disabled="page === 1 || loading || busy" @click="changePage(page - 1)">上一頁</button>
      <span>第 {{ page }} / {{ lastPage }} 頁 · 共 {{ payments?.meta.total ?? 0 }} 筆</span>
      <button class="secondary" type="button" :disabled="page === lastPage || loading || busy" @click="changePage(page + 1)">下一頁</button>
    </nav>
    <p v-else-if="payments" class="payment-count">共 {{ payments.meta.total }} 筆</p>
  </section>
</template>
