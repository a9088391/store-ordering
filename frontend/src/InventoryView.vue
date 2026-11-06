<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue'
import { ApiError, getInventory, getStores, type InventoryPage, type Store } from './api'

const props = defineProps<{ role: 'headquarters' | 'staff' }>()
const emit = defineEmits<{ logout: []; sessionExpired: [] }>()

const stores = shallowRef<Store[] | null>(null)
const storeId = ref<number | null>(null)
const storeFailure = ref(false)
const storesRetry = ref(0)
const inventory = shallowRef<InventoryPage | null>(null)
const inventoryFailure = ref(false)
const inventoryRetry = ref(0)
const page = ref(1)
const lastPage = ref(1)
const numberFormat = new Intl.NumberFormat('zh-TW')

// 受保護查詢失效時交由會話層確認身分，其他錯誤留在庫存畫面重試。
function isUnauthenticated(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401
}

// 門市清單屬於目前掛載的身分；重試或卸載時取消原查詢。
watch(storesRetry, async (_attempt, _previous, onCleanup) => {
  // 清除舊清單與選取，避免錯誤或重查期間殘留舊庫存。
  const controller = new AbortController()
  // 由 Vue 在重查或元件卸載時停止這次讀取。
  onCleanup(() => controller.abort())
  stores.value = null
  storeId.value = null
  storeFailure.value = false

  try {
    // 後端已篩出可見門市；有門市時直接顯示第一間的庫存。
    const visible = await getStores(controller.signal)
    if (controller.signal.aborted) return
    stores.value = visible
    storeId.value = visible[0]?.id ?? null
  } catch (error) {
    // 已取消的回應不能顯示錯誤或重新驗證新畫面的會話。
    if (controller.signal.aborted) return
    if (isUnauthenticated(error)) emit('sessionExpired')
    else storeFailure.value = true
  }
}, { immediate: true })

// 只有目前門市與頁碼的查詢可更新表格，切換時清除舊列。
watch([storeId, page, inventoryRetry], async ([id, currentPage], [previousId], onCleanup) => {
  // 保留同一門市的分頁控制；切到其他門市才重新計算。
  inventory.value = null
  inventoryFailure.value = false
  if (id !== previousId) lastPage.value = 1
  if (id === null) return

  // 每次選取或重試都結束前一次查詢的使用期限。
  const controller = new AbortController()
  onCleanup(() => controller.abort())

  try {
    // 忽略已取消的讀取，避免較晚完成的舊庫存蓋掉目前畫面。
    const result = await getInventory(id, currentPage, controller.signal)
    if (controller.signal.aborted) return
    inventory.value = result
    lastPage.value = Math.max(1, Math.ceil(result.meta.total / result.meta.perPage))
  } catch (error) {
    // 只有畫面正在等待的請求可觸發會話復原或查詢重試。
    if (controller.signal.aborted) return
    if (isUnauthenticated(error)) emit('sessionExpired')
    else inventoryFailure.value = true
  }
})

// 店員只需看見後端提供的本店名稱，總部才有門市選擇控制。
const staffStoreName = computed(() => stores.value?.[0]?.name)
</script>

<template>
  <section class="panel" aria-labelledby="inventory-heading">
    <div class="panel-header">
      <h2 id="inventory-heading">門市庫存</h2>
      <button class="secondary" type="button" @click="emit('logout')">登出</button>
    </div>

    <!-- 先讀取授權門市；清單失敗時不嘗試讀取庫存。 -->
    <div v-if="storeFailure" class="retry">
      <p role="alert">無法讀取門市，請重試。</p>
      <button type="button" @click="storesRetry++">重試</button>
    </div>
    <p v-else-if="!stores" role="status">正在讀取門市…</p>
    <p v-else-if="stores.length === 0" role="status">目前沒有可查詢的門市。</p>
    <template v-else>
      <div v-if="props.role === 'headquarters'" class="store-field">
        <label for="store">門市</label>
        <select id="store" v-model="storeId" @change="page = 1">
          <option v-for="store in stores" :key="store.id" :value="store.id">{{ store.name }}</option>
        </select>
      </div>
      <p v-else class="store-name">門市：{{ staffStoreName }}</p>

      <!-- 用原生欄位標題保留表格語意，整數零庫存與其他數值一樣顯示。 -->
      <div v-if="inventoryFailure" class="retry">
        <p role="alert">無法讀取庫存，請重試。</p>
        <button type="button" @click="inventoryRetry++">重試</button>
      </div>
      <p v-else-if="!inventory" role="status">正在讀取庫存…</p>
      <p v-else-if="inventory.data.length === 0" role="status">目前沒有庫存資料。</p>
      <table v-else aria-label="門市庫存明細">
        <thead>
          <tr>
            <th scope="col">商品</th>
            <th scope="col">單價</th>
            <th scope="col">實體庫存</th>
            <th scope="col">可售庫存</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in inventory.data" :key="item.id">
            <th scope="row">{{ item.name }}</th>
            <td>{{ numberFormat.format(item.price) }} 元</td>
            <td>{{ numberFormat.format(item.onHand) }}</td>
            <td>{{ numberFormat.format(item.available) }}</td>
          </tr>
        </tbody>
      </table>

      <!-- 單頁時無需分頁控制；同一門市換頁時維持控制位置。 -->
      <nav v-if="lastPage > 1" class="pagination" aria-label="庫存分頁">
        <button class="secondary" type="button" :disabled="page === 1" @click="page--">上一頁</button>
        <span>{{ page }} / {{ lastPage }} 頁</span>
        <button class="secondary" type="button" :disabled="page === lastPage" @click="page++">下一頁</button>
      </nav>
    </template>
  </section>
</template>
