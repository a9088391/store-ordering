<script setup lang="ts">
import { watch } from 'vue'
import { numberFormat } from './orderPresentation'
import { useInventory } from './useInventory'

const props = defineProps<{ storeId: number; stockRevision: number }>()
const emit = defineEmits<{ sessionExpired: []; create: [] }>()

// 庫存查詢和頁碼只在相同門市內共用；授權失效則交回會話入口。
const { data: inventory, failed, page, lastPage, refresh } =
  useInventory(props.storeId, () => emit('sessionExpired'))

// 建單、取消或付款可能改變庫存，即使暫離庫存畫面也更新同一頁。
watch(() => props.stockRevision, refresh)
</script>

<template>
  <section aria-labelledby="inventory-heading">
    <header class="heading">
      <h1 id="inventory-heading" tabindex="-1">門市庫存</h1>
      <button type="button" @click="emit('create')">建立訂單</button>
    </header>
    <div v-if="failed" class="retry">
      <p role="alert">無法讀取庫存，請重試。</p>
      <button type="button" @click="refresh">重試</button>
    </div>
    <p v-else-if="!inventory" role="status">正在讀取庫存…</p>
    <p v-else-if="inventory.data.length === 0" class="notice" role="status">目前沒有庫存資料。</p>
    <div v-else class="table-wrap" tabindex="0" role="region" aria-label="門市庫存表格">
      <table class="inventory-table" aria-label="門市庫存明細">
        <thead>
          <tr>
            <th scope="col">商品</th>
            <th scope="col" class="num">單價</th>
            <th scope="col" class="num">實體庫存</th>
            <th scope="col" class="num">可售庫存</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in inventory.data" :key="item.id">
            <th scope="row">{{ item.name }}</th>
            <td class="num">TWD {{ numberFormat.format(item.price) }}</td>
            <td class="num">{{ numberFormat.format(item.onHand) }}</td>
            <td class="num">{{ numberFormat.format(item.available) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <nav v-if="lastPage > 1" class="pagination" aria-label="庫存分頁">
      <button class="secondary" type="button" :disabled="page === 1" @click="page--">上一頁</button>
      <span>{{ page }} / {{ lastPage }} 頁</span>
      <button class="secondary" type="button" :disabled="page === lastPage" @click="page++">下一頁</button>
    </nav>
  </section>
</template>
