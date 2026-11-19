<script setup lang="ts">
import { computed } from 'vue'
import type { OrderDetail, Store } from './api'
import {
  anomalyLabels, dateFormat, numberFormat, paymentStatusLabels, pendingReasonLabels, statusLabels, stockMovementLabels,
} from './orderPresentation'
import type { PaymentAction } from './useOrderDetail'

const props = defineProps<{
  orderId: number
  store: Store
  detail: OrderDetail | null
  error: string
  busy: boolean
  cancelling: boolean
  paymentAction: PaymentAction | null
  createFeedback: string
  cancelFeedback: string
  paymentFeedback: string
}>()
const emit = defineEmits<{ back: []; retry: []; cancel: []; payment: [action: PaymentAction] }>()

// 一次對照訂購明細的品項名稱，異動紀錄仍以原本的品項識別為準。
const productNames = computed(() => {
  return new Map(props.detail?.items.map((item) => [item.productId, item.name]))
})
</script>

<template>
  <section class="order-panel" aria-labelledby="order-detail-heading">
    <button class="back row-link" type="button" :disabled="busy" @click="emit('back')">← 返回訂單清單</button>
    <header class="heading detail-head">
      <div class="detail-title">
        <h1 id="order-detail-heading" tabindex="-1">訂單 #{{ orderId }}</h1>
        <span v-if="detail" class="status" :class="detail.status">{{ statusLabels[detail.status] }}</span>
      </div>
      <div v-if="detail" class="actions">
        <template v-if="detail.status === 'awaiting_payment'">
          <button class="secondary" type="button" :disabled="busy" @click="emit('cancel')">
            {{ cancelling ? '取消確認中…' : '取消訂單' }}
          </button>
          <button type="button" :disabled="busy" @click="emit('payment', 'start')">
            {{ paymentAction === 'start' ? '付款發起中…' : '發起付款' }}
          </button>
        </template>
        <button
          v-else-if="['processing', 'paid', 'failed'].includes(detail.status)"
          class="secondary" type="button" :disabled="busy" @click="emit('payment', 'query')"
        >{{ paymentAction === 'query' ? '查詢中…' : '查詢付款結果' }}</button>
      </div>
    </header>
    <p v-if="createFeedback" class="notice" role="status">{{ createFeedback }}</p>
    <p v-if="cancelFeedback" class="notice" role="status">{{ cancelFeedback }}</p>
    <p v-if="paymentFeedback" class="notice" role="status">{{ paymentFeedback }}</p>
    <p v-if="cancelling" role="status">正在確認取消…</p>
    <p v-if="paymentAction" role="status">
      {{ paymentAction === 'start' ? '正在發起付款…' : '正在查詢付款結果…' }}
    </p>
    <div v-if="error" class="retry">
      <p role="alert">{{ error }}</p>
      <button type="button" :disabled="busy" @click="emit('retry')">重新讀取訂單詳情</button>
    </div>
    <p v-else-if="!detail" role="status">正在讀取訂單詳情…</p>
    <div v-else class="detail-grid">
      <section aria-labelledby="order-items-heading">
        <h2 id="order-items-heading">訂購明細</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="訂購明細表格">
          <table class="lines-table" aria-label="訂購明細">
            <thead>
              <tr>
                <th scope="col">品項</th>
                <th scope="col" class="num">數量</th>
                <th scope="col" class="num">單價（TWD）</th>
                <th scope="col" class="num">小計（TWD）</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in detail.items" :key="item.productId">
                <th scope="row">{{ item.name }}</th>
                <td class="num">{{ numberFormat.format(item.quantity) }}</td>
                <td class="num">{{ numberFormat.format(item.unitPrice) }}</td>
                <td class="num">{{ numberFormat.format(item.subtotal) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="total"><span>訂單金額</span><strong>TWD {{ numberFormat.format(detail.total) }}</strong></div>
        <section aria-labelledby="payment-information-heading">
          <h2 id="payment-information-heading">付款資訊</h2>
          <dl class="order-summary">
            <dt>付款狀態</dt>
            <dd v-if="detail.payment"><span class="status" :class="detail.payment.status">{{ paymentStatusLabels[detail.payment.status] }}</span></dd>
            <dd v-else>尚未建立付款</dd>
            <template v-if="detail.payment">
              <dt>付款識別</dt><dd class="payment-id">{{ detail.payment.id }}</dd>
              <template v-if="detail.payment.pendingReason">
                <dt>待處理原因</dt><dd>{{ pendingReasonLabels[detail.payment.pendingReason] }}</dd>
              </template>
            </template>
            <dt>付款異常</dt>
            <dd>
              <ul v-if="detail.anomalies.length" class="anomalies">
                <li v-for="(anomaly, index) in detail.anomalies" :key="index">
                  {{ anomalyLabels[anomaly.reason] }} ·
                  <time :datetime="anomaly.occurredAt">{{ dateFormat.format(new Date(anomaly.occurredAt)) }}</time>
                </li>
              </ul>
              <template v-else>無付款異常紀錄。</template>
            </dd>
          </dl>
        </section>
        <section aria-labelledby="order-information-heading">
          <h2 id="order-information-heading">訂單資訊</h2>
          <dl class="order-summary">
            <dt>門市</dt><dd>{{ store.name }}</dd>
            <dt>建立人編號</dt><dd>{{ detail.createdBy }}</dd>
            <dt>建立時間</dt><dd><time :datetime="detail.createdAt">{{ dateFormat.format(new Date(detail.createdAt)) }}</time></dd>
            <dt>到期時間</dt><dd><time :datetime="detail.expiresAt">{{ dateFormat.format(new Date(detail.expiresAt)) }}</time></dd>
          </dl>
        </section>
      </section>
      <section aria-labelledby="order-history-heading">
        <h2 id="order-history-heading">狀態歷程</h2>
        <ol class="timeline" role="list" aria-label="狀態歷程">
          <li v-for="(event, index) in detail.history" :key="index">
            <strong>{{ statusLabels[event.status] }}</strong>
            <time :datetime="event.occurredAt">{{ dateFormat.format(new Date(event.occurredAt)) }}</time>
          </li>
        </ol>
        <section aria-labelledby="stock-ledger-heading">
          <h2 id="stock-ledger-heading">庫存異動紀錄</h2>
          <div class="table-wrap" tabindex="0" role="region" aria-label="庫存異動表格">
            <table class="ledger" aria-label="庫存異動紀錄">
              <thead>
                <tr>
                  <th scope="col">異動</th>
                  <th scope="col">品項</th>
                  <th scope="col" class="num">數量</th>
                  <th scope="col">時間</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(entry, index) in detail.stockLedger" :key="index">
                  <td>{{ stockMovementLabels[entry.type] }}</td>
                  <th scope="row">{{ productNames.get(entry.productId) ?? `商品 #${entry.productId}` }}</th>
                  <td class="num">{{ numberFormat.format(entry.quantity) }}</td>
                  <td><time :datetime="entry.occurredAt">{{ dateFormat.format(new Date(entry.occurredAt)) }}</time></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>
    </div>
  </section>
</template>
