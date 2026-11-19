<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import type { Store } from './api'
import CreateOrderPage from './CreateOrderPage.vue'
import OrderDetailPage from './OrderDetailPage.vue'
import OrderListPage from './OrderListPage.vue'
import { useOrderDetail } from './useOrderDetail'
import { useOrderDraft } from './useOrderDraft'
import { useOrderList } from './useOrderList'
import type { OrderScope, WorkspacePage } from './workspace'

const props = defineProps<{ store: Store; actorId: number; csrfToken: string }>()
const page = defineModel<WorkspacePage>('page', { required: true })
const emit = defineEmits<{ sessionExpired: []; stockChanged: []; busy: [active: boolean] }>()

// 此元件以帳號與門市為鍵；共用的寫入與回讀不依附在個別頁面。
const lifetime = new AbortController()
const createdFeedback = ref('')
const { filters, filterError, page: listPage, lastPage, list, listError, applyFilters, refreshList } =
  useOrderList(props.store.id, sessionExpired)
const scope: OrderScope = {
  actorId: props.actorId,
  storeId: props.store.id,
  signal: lifetime.signal,
  // 送出當下才取目前 token；重讀會話後不重用舊 token。
  csrfToken: () => props.csrfToken,
  // 即時共用建單、取消與付款的互斥狀態。
  writeBusy: () => writeBusy.value,
  sessionExpired,
  stockChanged,
}
const {
  selectedOrder, detail, detailError, cancelling, paymentAction, cancelFeedback, paymentFeedback,
  viewOrder, leaveDetail, acceptCreated, readDetail, requestCancel, requestPayment,
} = useOrderDetail(scope, focusDetail)
const catalogActive = computed(() => page.value === 'create')
const {
  catalog: { data: catalog, failed: catalogError, page: productPage, lastPage: productLastPage, refresh: refreshCatalog },
  selectableProducts, productId, lines, pendingOrder, storageBlocked, creating, draftFeedback,
  restorePending, addLine, submitOrder, updateQuantity, removeLine, changeCatalogPage,
} = useOrderDraft(scope, catalogActive, acceptCreated, showCreatedOrder)
const writeBusy = computed(() => creating.value || cancelling.value || paymentAction.value !== null)

// 同步通知外層鎖住導覽及身分操作，直到寫入及必要回讀結束。
watch(writeBusy, (active) => emit('busy', active), { flush: 'sync' })

// 離開詳情取消已不顯示的 GET；回清單時以相同條件讀取最新資料。
watch(page, (current, previous) => {
  // 確認提示只屬於剛建立的詳情；離開後仍可從清單正常讀取該訂單。
  if (previous === 'detail' && current !== 'detail') {
    leaveDetail()
    createdFeedback.value = ''
  }
  // 清單回讀沿用已套用的條件。
  if (current === 'list') {
    filterError.value = ''
    refreshList()
  }
})

// 身分確認由 App 單獨處理；工作區不能自行重送任何操作。
function sessionExpired(): void {
  emit('sessionExpired')
}

// 訂單操作可能影響庫存，更新同一門市的清單及庫存。
function stockChanged(): void {
  refreshList()
  refreshCatalog()
  emit('stockChanged')
}

// 讀取選到的既有訂單，建立草稿回饋仍留在建立頁。
function openOrder(id: number): void {
  if (writeBusy.value) return
  viewOrder(id)
  page.value = 'detail'
}

// 成功的新建單或同本文重送會直接到已驗證的訂單。
function showCreatedOrder(orderId: number, draftCleared: boolean): void {
  // 成功確認與瀏覽器保存狀態可同時成立，提示在建立結果頁一併說明。
  const confirmed = `已確認訂單 #${orderId}。`
  createdFeedback.value = draftCleared ? confirmed : `${confirmed} 瀏覽器仍保留待確認紀錄。`
  page.value = 'detail'
}

// 返回清單只改畫面；保留篩選、分頁與待確認本文。
function returnToList(): void {
  if (!writeBusy.value) page.value = 'list'
}

// 取消只捨棄尚未送出的品項；不移除原本的待確認操作。
function cancelCreate(): void {
  if (writeBusy.value) return
  if (!pendingOrder.value) {
    lines.value = []
    productId.value = null
    draftFeedback.value = ''
  }
  returnToList()
}

// 庫存與列表操作後的重試是唯讀，不再次執行付款或取消。
function retryDetail(): void {
  const id = selectedOrder.value
  if (writeBusy.value || id === null) return
  void readDetail(id, true)
  void focusDetail(id)
}

// 操作使原按鈕消失時，將焦點留在同一張訂單的標題。
async function focusDetail(id: number): Promise<void> {
  await nextTick()
  if (
    !lifetime.signal.aborted
    && page.value === 'detail'
    && selectedOrder.value === id
  ) document.getElementById('order-detail-heading')?.focus()
}

// 切換帳號或門市才中止整個工作區的請求。
onBeforeUnmount(() => lifetime.abort())
</script>

<template>
  <OrderListPage
    v-if="page === 'list'"
    :filters="filters"
    :page="listPage"
    :last-page="lastPage"
    :list="list"
    :list-error="listError"
    :filter-error="filterError"
    :has-pending="pendingOrder !== null"
    :storage-blocked="storageBlocked"
    :busy="writeBusy"
    @apply="applyFilters"
    @change-page="listPage = $event"
    @create="page = 'create'"
    @open="openOrder"
    @refresh="refreshList"
  />
  <CreateOrderPage
    v-else-if="page === 'create'"
    v-model:product-id="productId"
    :products="selectableProducts"
    :catalog="catalog"
    :catalog-error="catalogError"
    :product-page="productPage"
    :product-last-page="productLastPage"
    :lines="lines"
    :has-pending="pendingOrder !== null"
    :storage-blocked="storageBlocked"
    :busy="writeBusy"
    :creating="creating"
    :feedback="draftFeedback"
    @back="returnToList"
    @cancel="cancelCreate"
    @retry="refreshCatalog"
    @restore="restorePending"
    @change-page="changeCatalogPage"
    @add-line="addLine"
    @remove-line="removeLine"
    @quantity="updateQuantity"
    @submit="submitOrder"
  />
  <OrderDetailPage
    v-else-if="page === 'detail' && selectedOrder !== null"
    :order-id="selectedOrder"
    :store="store"
    :detail="detail"
    :error="detailError"
    :busy="writeBusy"
    :cancelling="cancelling"
    :payment-action="paymentAction"
    :create-feedback="createdFeedback"
    :cancel-feedback="cancelFeedback"
    :payment-feedback="paymentFeedback"
    @back="returnToList"
    @retry="retryDetail"
    @cancel="requestCancel"
    @payment="requestPayment"
  />
</template>
