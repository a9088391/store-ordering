<script setup lang="ts">
import { ref } from 'vue'
import type { OrderFilters, OrderPage } from './api'
import { dateFormat, numberFormat, statusLabels } from './orderPresentation'

const props = defineProps<{
  filters: OrderFilters
  page: number
  lastPage: number
  list: OrderPage | null
  listError: string
  filterError: string
  hasPending: boolean
  storageBlocked: boolean
  busy: boolean
}>()
const emit = defineEmits<{
  apply: [fields: OrderFilters]
  changePage: [page: number]
  create: []
  open: [id: number]
  refresh: []
}>()

// 畫面只編輯尚未送出的條件；返回清單時以工作區的已套用條件初始化。
const fields = ref({ ...props.filters })

// 清空編輯欄位並套用，讓新結果從第一頁開始。
function resetFilters(): void {
  fields.value = { orderNumber: '', status: '', from: '', to: '' }
  emit('apply', fields.value)
}
</script>

<template>
  <section class="order-panel" aria-labelledby="order-list-heading">
    <header class="heading">
      <h1 id="order-list-heading" tabindex="-1">訂單清單</h1>
      <button type="button" :disabled="busy" @click="emit('create')">
        {{ hasPending ? '查看待確認訂單' : '建立訂單' }}
      </button>
    </header>
    <p v-if="hasPending" class="notice" role="status">
      此門市有一筆待確認的訂單。可回到建立訂單畫面，以原內容確認同一筆訂單。
    </p>
    <p v-else-if="storageBlocked" class="notice" role="alert">
      無法讀取待確認訂單，請在建立訂單畫面重新讀取。
    </p>
    <form class="filters" aria-label="訂單篩選" @submit.prevent="emit('apply', fields)">
      <div>
        <label for="order-number">訂單編號</label>
        <input id="order-number" v-model="fields.orderNumber" inputmode="numeric" pattern="[1-9][0-9]*" placeholder="例如 123">
      </div>
      <div>
        <label for="order-status">訂單狀態</label>
        <select id="order-status" v-model="fields.status">
          <option value="">全部狀態</option>
          <option v-for="(label, status) in statusLabels" :key="status" :value="status">{{ label }}</option>
        </select>
      </div>
      <div>
        <label for="order-from">建立起日（含）</label>
        <input id="order-from" v-model="fields.from" type="date" min="0001-01-01" max="9999-12-31">
      </div>
      <div>
        <label for="order-to">建立迄日（不含）</label>
        <input id="order-to" v-model="fields.to" type="date" min="0001-01-01" max="9999-12-31">
      </div>
      <div class="actions">
        <button type="submit">套用篩選</button>
        <button class="secondary" type="button" @click="resetFilters">清除篩選</button>
      </div>
      <p v-if="filterError" class="filter-error" role="alert">{{ filterError }}</p>
    </form>
    <p class="help">篩選日期以台北時間為準。</p>

    <div v-if="listError" class="retry">
      <p role="alert">{{ listError }}</p>
      <button type="button" @click="emit('refresh')">重新讀取訂單清單</button>
    </div>
    <p v-else-if="!list" role="status">正在讀取訂單清單…</p>
    <p v-else-if="list.data.length === 0" class="notice" role="status">找不到符合條件的訂單。</p>
    <div v-else class="table-wrap" tabindex="0" role="region" aria-label="訂單清單表格">
      <table class="list-table" aria-label="訂單查詢結果">
        <thead>
          <tr>
            <th scope="col">訂單編號</th>
            <th scope="col">建立時間</th>
            <th scope="col">訂單狀態</th>
            <th scope="col" class="num">訂單金額</th>
            <th scope="col" class="num">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="order in list.data" :key="order.id">
            <th scope="row">#{{ order.id }}</th>
            <td><time :datetime="order.createdAt">{{ dateFormat.format(new Date(order.createdAt)) }}</time></td>
            <td><span class="status" :class="order.status">{{ statusLabels[order.status] }}</span></td>
            <td class="num">TWD {{ numberFormat.format(order.total) }}</td>
            <td class="num">
              <button
                class="row-link"
                type="button"
                :aria-label="`查看訂單 #${order.id}`"
                :disabled="busy"
                @click="emit('open', order.id)"
              >查看詳情</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <nav v-if="lastPage > 1" class="pagination" aria-label="訂單分頁">
      <button class="secondary" type="button" :disabled="page === 1" @click="emit('changePage', page - 1)">上一頁</button>
      <span>{{ page }} / {{ lastPage }} 頁</span>
      <button class="secondary" type="button" :disabled="page >= lastPage" @click="emit('changePage', page + 1)">下一頁</button>
    </nav>
  </section>
</template>
