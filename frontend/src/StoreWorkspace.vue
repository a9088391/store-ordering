<script setup lang="ts">
import { computed, ref, shallowRef, useTemplateRef, watch } from 'vue'
import { getStores, type Store } from './api'
import HeadquartersPaymentsView from './HeadquartersPaymentsView.vue'
import InventoryView from './InventoryView.vue'
import OrderWorkspace from './OrderWorkspace.vue'
import { needsSession, type WorkspacePage } from './workspace'

const props = defineProps<{ role: 'headquarters' | 'staff'; actorId: number; csrfToken: string; feedback: string }>()
const emit = defineEmits<{ logout: []; sessionExpired: [] }>()

const stores = shallowRef<Store[] | null>(null)
const storeId = ref<number | null>(null)
const storeFailure = ref(false)
const storesRetry = ref(0)
const activePage = ref<WorkspacePage>('inventory')
const paymentsOpened = ref(false)
const orderBusy = ref(false)
const paymentBusy = ref(false)
const stockRevision = ref(0)
const main = useTemplateRef<HTMLElement>('main')

// 只採用後端回傳的授權清單，單一門市沒有切換控制。
const store = computed(() => stores.value?.find((entry) => entry.id === storeId.value))
const busy = computed(() => orderBusy.value || paymentBusy.value)
const orderPage = computed(() => ['list', 'create', 'detail'].includes(activePage.value))

const headingId: Record<WorkspacePage, string> = {
  inventory: 'inventory-heading',
  list: 'order-list-heading',
  create: 'create-order-heading',
  detail: 'order-detail-heading',
  payments: 'payments-heading',
}

// 門市清單屬於目前帳號；重試或卸載時取消原查詢。
watch(storesRetry, async (_attempt, _previous, onCleanup) => {
  // 清除舊選取，避免重試期間顯示舊門市內容。
  const controller = new AbortController()
  onCleanup(() => controller.abort())
  stores.value = null
  storeId.value = null
  storeFailure.value = false

  try {
    // 後端已篩出可見門市；第一間門市為登入後的預設。
    const visible = await getStores(controller.signal)
    if (controller.signal.aborted) return
    stores.value = visible
    storeId.value = visible[0]?.id ?? null
  } catch (error) {
    // 已取消的讀取不能重新驗證新畫面的會話。
    if (controller.signal.aborted) return
    if (needsSession(error)) emit('sessionExpired')
    else storeFailure.value = true
  }
}, { immediate: true })

// 順著使用者選取移動焦點；離開詳情後可從清單頂端繼續操作。
watch(activePage, (page) => {
  const target = main.value?.querySelector<HTMLElement>(`#${headingId[page]}`)
  target?.focus()
}, { flush: 'post' })

// 店員不能開啟總部付款；進行中的寫入或回讀不接受導覽。
function navigate(page: 'inventory' | 'list' | 'create' | 'payments'): void {
  if (busy.value) return
  // 總部面板與門市查詢獨立；回庫存時可讀到背景結算的更新。
  if (page === 'payments') {
    if (props.role !== 'headquarters') return
    paymentsOpened.value = true
  } else if (page !== 'inventory' && !store.value) {
    return
  }
  if (page === 'inventory' && activePage.value !== page) stockRevision.value++
  activePage.value = page
}

// 門市切換清空舊店詳情或建單畫面，總部付款不依賴門市。
function changeStore(event: Event): void {
  if (busy.value) return
  // 再核對清單，避免接受不屬於此帳號的 DOM 選項。
  const next = Number((event.target as HTMLSelectElement).value)
  if (!stores.value?.some((entry) => entry.id === next)) return
  storeId.value = next
  if (orderPage.value) activePage.value = 'list'
}

// 未完成的業務寫入不能因登出動作卸載。
function requestLogout(): void {
  if (!busy.value) emit('logout')
}
</script>

<template>
  <div class="frame">
    <aside class="sidebar">
      <span class="brand">門市訂單系統</span>
      <nav aria-label="工作區">
        <button
          type="button" :aria-current="activePage === 'inventory' ? 'page' : undefined" :disabled="busy"
          @click="navigate('inventory')"
        >門市庫存</button>
        <button
          type="button" :aria-current="orderPage ? 'page' : undefined" :disabled="busy || !store"
          @click="navigate('list')"
        >訂單清單</button>
        <button
          v-if="role === 'headquarters'" type="button"
          :aria-current="activePage === 'payments' ? 'page' : undefined" :disabled="busy"
          @click="navigate('payments')"
        >總部模擬付款</button>
      </nav>
    </aside>

    <div class="work">
      <header class="bar">
        <div v-if="role === 'headquarters' && stores?.length" class="store-field">
          <label for="store">門市</label>
          <select id="store" :value="storeId" :disabled="busy" @change="changeStore">
            <option v-for="entry in stores" :key="entry.id" :value="entry.id">{{ entry.name }}</option>
          </select>
        </div>
        <span v-else-if="role === 'headquarters'">總部</span>
        <span v-else-if="store">門市：{{ store.name }}</span>
        <button class="row-link logout" type="button" :disabled="busy" @click="requestLogout">登出</button>
      </header>

      <main ref="main" class="workspace-main">
        <p v-if="feedback" class="notice" role="status">{{ feedback }}</p>
        <section v-if="activePage !== 'payments' && !store" aria-labelledby="inventory-heading">
          <header class="heading"><h1 id="inventory-heading" tabindex="-1">門市庫存</h1></header>
          <div v-if="storeFailure" class="retry">
            <p role="alert">無法讀取門市，請重試。</p>
            <button type="button" @click="storesRetry++">重試</button>
          </div>
          <p v-else-if="!stores" role="status">正在讀取門市…</p>
          <p v-else class="notice" role="status">目前沒有可查詢的門市。</p>
        </section>
        <!-- 庫存頁保留頁碼並在訂單異動後回讀；隱藏時不進入鍵盤導覽。 -->
        <InventoryView
          v-if="store"
          v-show="activePage === 'inventory'"
          :key="store.id"
          :store-id="store.id"
          :stock-revision="stockRevision"
          @create="navigate('create')"
          @session-expired="emit('sessionExpired')"
        />
        <OrderWorkspace
          v-if="store"
          :key="store.id"
          v-model:page="activePage"
          :store="store"
          :actor-id="actorId"
          :csrf-token="csrfToken"
          @stock-changed="stockRevision++"
          @busy="orderBusy = $event"
          @session-expired="emit('sessionExpired')"
        />
        <!-- 總部付款不隨門市選取重建，保留各自的回讀與派送資訊。 -->
        <HeadquartersPaymentsView
          v-if="role === 'headquarters' && paymentsOpened"
          v-show="activePage === 'payments'"
          :active="activePage === 'payments'"
          :csrf-token="csrfToken"
          @busy="paymentBusy = $event"
          @session-expired="emit('sessionExpired')"
        />
      </main>
    </div>
  </div>
</template>
