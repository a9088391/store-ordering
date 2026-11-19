<script setup lang="ts">
import { computed } from 'vue'
import type { InventoryItem, InventoryPage } from './api'
import { numberFormat } from './orderPresentation'
import { parseDraftQuantity, type DraftLine } from './useOrderDraft'

const props = defineProps<{
  products: InventoryItem[]
  lines: DraftLine[]
  catalog: InventoryPage | null
  catalogError: boolean
  productPage: number
  productLastPage: number
  hasPending: boolean
  storageBlocked: boolean
  busy: boolean
  creating: boolean
  feedback: string
}>()
const productId = defineModel<number | null>('productId', { required: true })
const emit = defineEmits<{
  back: []
  cancel: []
  retry: []
  restore: []
  changePage: [page: number]
  addLine: []
  removeLine: [id: number]
  quantity: [id: number, quantity: string]
  submit: []
}>()

// 從目前商品頁補齊復原品項；跨頁草稿優先使用加入時保留的名稱和單價。
const rows = computed(() => props.lines.map((line) => {
  // 復原資料只含送出的欄位，缺少單價時以空值呈現，不能誤報總額。
  const product = props.catalog?.data.find((item) => item.id === line.productId)
  const name = line.name ?? product?.name ?? `商品 #${line.productId}`
  const unitPrice = line.unitPrice ?? product?.price
  const quantity = parseDraftQuantity(line.quantity)
  const subtotal = unitPrice
    && quantity !== undefined
    && quantity <= Math.floor(Number.MAX_SAFE_INTEGER / unitPrice)
    ? unitPrice * quantity : null
  return { ...line, name, unitPrice, subtotal }
}))

// 總額只作預覽；在加總前核對安全整數範圍，實際金額仍由後端計算。
const total = computed(() => {
  let sum = 0
  for (const row of rows.value) {
    if (row.subtotal === null || row.subtotal > Number.MAX_SAFE_INTEGER - sum) return null
    sum += row.subtotal
  }
  return sum
})

// 直接傳遞使用者輸入的文字，避免用數字轉型接受不符合契約的內容。
function updateQuantity(productId: number, event: Event): void {
  const input = event.target as HTMLInputElement
  emit('quantity', productId, input.value)
}
</script>

<template>
  <section class="order-panel" aria-labelledby="create-order-heading">
    <button class="back row-link" type="button" :disabled="busy" @click="emit('back')">← 返回訂單清單</button>
    <header class="heading"><h1 id="create-order-heading" tabindex="-1">建立訂單</h1></header>
    <p v-if="feedback" class="notice" role="status">{{ feedback }}</p>
    <div v-if="storageBlocked" class="retry">
      <p role="alert">無法讀取待確認訂單，目前無法建立新訂單。</p>
      <button type="button" :disabled="busy" @click="emit('restore')">重新讀取待確認訂單</button>
    </div>

    <form class="order-form" @submit.prevent="emit('submit')">
      <p v-if="hasPending" class="notice" role="status">
        此門市有一筆待確認的訂單。確認會使用相同品項、數量及操作鍵。
      </p>
      <template v-else>
        <p v-if="catalogError" class="notice" role="alert">無法讀取商品，請重試。</p>
        <p v-else-if="!catalog" role="status">正在讀取商品…</p>
        <p v-else-if="catalog.data.length === 0" role="status">目前沒有可選商品。</p>
        <fieldset class="form-tools" :disabled="storageBlocked || busy">
          <legend class="visually-hidden">訂購品項（最多 100 項）</legend>
          <label for="order-product">商品</label>
          <select id="order-product" v-model="productId" :disabled="products.length === 0 || lines.length >= 100">
            <option :value="null" disabled>選擇商品</option>
            <option v-for="product in products" :key="product.id" :value="product.id">
              {{ product.name }}
            </option>
          </select>
          <button
            class="secondary"
            type="button"
            :disabled="productId === null || lines.length >= 100"
            @click="emit('addLine')"
          >新增品項</button>
        </fieldset>
        <button v-if="catalogError" class="secondary" type="button" :disabled="busy" @click="emit('retry')">重新讀取商品</button>
        <nav v-if="productLastPage > 1" class="pagination catalog-pages" aria-label="商品分頁">
          <button
            class="secondary" type="button" :disabled="productPage === 1 || busy"
            @click="emit('changePage', productPage - 1)"
          >上一頁</button>
          <span>{{ productPage }} / {{ productLastPage }} 頁</span>
          <button
            class="secondary" type="button" :disabled="productPage >= productLastPage || busy"
            @click="emit('changePage', productPage + 1)"
          >下一頁</button>
        </nav>
      </template>

      <fieldset class="draft-lines" :disabled="hasPending || storageBlocked || busy">
        <legend class="visually-hidden">已選品項</legend>
        <div v-if="rows.length" class="table-wrap" tabindex="0" role="region" aria-label="訂購品項表格">
          <table class="form-table" aria-label="訂購品項">
            <thead>
              <tr>
                <th scope="col">品項</th>
                <th scope="col">數量</th>
                <th scope="col" class="num">單價</th>
                <th scope="col" class="num">小計</th>
                <th scope="col">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in rows" :key="line.productId">
                <th scope="row">{{ line.name }}</th>
                <td>
                  <input
                    class="quantity"
                    :aria-label="`數量（${line.name}）`"
                    :value="line.quantity"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]+"
                    required
                    @input="updateQuantity(line.productId, $event)"
                  >
                </td>
                <td class="num">{{ line.unitPrice === undefined ? '—' : `TWD ${numberFormat.format(line.unitPrice)}` }}</td>
                <td class="num">{{ line.subtotal === null ? '—' : `TWD ${numberFormat.format(line.subtotal)}` }}</td>
                <td>
                  <button
                    class="secondary" type="button" :aria-label="`移除品項（${line.name}）`"
                    @click="emit('removeLine', line.productId)"
                  >移除品項</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="notice">尚未新增品項。</p>
      </fieldset>
      <div class="total">
        <span>預估訂單金額</span><strong>{{ total === null ? '—' : `TWD ${numberFormat.format(total)}` }}</strong>
      </div>
      <p v-if="creating" class="notice" role="status">正在確認訂單…</p>
      <div class="actions footer-actions">
        <button
          v-if="hasPending"
          type="button"
          :disabled="storageBlocked || busy"
          @click="emit('submit')"
        >確認同一筆訂單</button>
        <button v-else type="submit" :disabled="lines.length === 0 || storageBlocked || busy">建立訂單</button>
        <button class="secondary" type="button" :disabled="busy" @click="emit('cancel')">取消</button>
      </div>
    </form>
  </section>
</template>
