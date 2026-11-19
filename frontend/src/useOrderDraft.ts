import { computed, onMounted, ref, shallowRef, type Ref } from 'vue'
import { ApiError, createOrder, isPositiveSafeInteger, readOrderDraft, type OrderDetail, type OrderDraft } from './api'
import { needsSession, type OrderScope } from './workspace'
import { useInventory } from './useInventory'

export type DraftLine = { productId: number; name?: string; unitPrice?: number; quantity: string }
type PendingOrder = { body: string; draft: OrderDraft }

// 預估與送出共用欄位契約，接受前導零與 ASCII 數字，數值仍須為正安全整數。
export function parseDraftQuantity(raw: string): number | undefined {
  if (!/^[0-9]+$/.test(raw)) return
  const quantity = Number(raw)
  return isPositiveSafeInteger(quantity) ? quantity : undefined
}

// 建單草稿與待確認本文屬於同一帳號、門市，不隨畫面切換卸載。
export function useOrderDraft(
  scope: OrderScope,
  catalogActive: Ref<boolean>,
  acceptCreated: (order: OrderDetail) => void,
  completed: (orderId: number, draftCleared: boolean) => void,
) {
  const catalog = useInventory(scope.storeId, scope.sessionExpired, catalogActive)
  const storageKey = `store-ordering:pending-order:${scope.actorId}:${scope.storeId}`
  const lines = ref<DraftLine[]>([])
  const productId = ref<number | null>(null)
  const pendingOrder = shallowRef<PendingOrder | null>(null)
  const storageBlocked = ref(false)
  const creating = ref(false)
  const draftFeedback = ref('')

  // 只列出庫存目前頁可新增的商品，草稿可保留其他頁已選的品項。
  const selectableProducts = computed(() => {
    const selected = new Set(lines.value.map((line) => line.productId))
    return (catalog.data.value?.data ?? []).filter((product) => !selected.has(product.id))
  })
  // 選取值只屬於目前授權商品頁。
  const selectedProduct = computed(() => selectableProducts.value.find((product) => product.id === productId.value))

  // 將原始本文帶回畫面；未驗證的資料會鎖住建立功能以免重複開單。
  function restorePending(): void {
    try {
      // 以原字串重送；解析結果僅用於核對與回填欄位。
      const body = sessionStorage.getItem(storageKey)
      if (body !== null) {
        const draft = readOrderDraft(body, scope.storeId)
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
    return line.name ?? catalog.data.value?.data.find((product) => product.id === line.productId)?.name ?? `商品 #${line.productId}`
  }

  // 從目前授權庫存加入品項，數量由草稿列中的原生欄位調整。
  function addLine(): void {
    if (pendingOrder.value || lines.value.length >= 100) return
    const product = selectedProduct.value
    if (!product) return
    // 草稿切換商品頁時須保留當時顯示的名稱及單價。
    lines.value.push({ productId: product.id, name: product.name, unitPrice: product.price, quantity: '1' })
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
    if (scope.writeBusy() || storageBlocked.value) return
    draftFeedback.value = ''
    let attempt = pendingOrder.value
    if (!attempt) {
      if (lines.value.length === 0 || lines.value.length > 100) return

      // 從欄位原始文字核對正整數，避免小數或超界數值被轉成可提交的整數。
      const items = []
      for (const line of lines.value) {
        const quantity = parseDraftQuantity(line.quantity)
        if (quantity === undefined) {
          draftFeedback.value = `${lineName(line)}的數量必須為 1 到 9,007,199,254,740,991 的整數。`
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
        const draft = { storeId: scope.storeId, operationKey: crypto.randomUUID(), items }
        const body = JSON.stringify(draft)
        sessionStorage.setItem(storageKey, body)
        attempt = { body, draft }
        pendingOrder.value = attempt
      } catch {
        draftFeedback.value = '無法保存待確認的訂單資料，訂單尚未送出。'
        return
      }
    }

    creating.value = true
    try {
      // 確認帳號、門市及品項後才解除草稿鎖定，並回讀清單與庫存。
      const order = await createOrder(attempt.body, attempt.draft, scope.actorId, scope.csrfToken(), scope.signal)
      if (scope.signal.aborted) return
      acceptCreated(order)
      const draftCleared = clearPending(attempt)
      if (draftCleared) {
        lines.value = []
        productId.value = null
      }
      // 把確認與清理結果交給該筆詳情，建立表單只保留草稿錯誤與待確認資訊。
      completed(order.id, draftCleared)
      scope.stockChanged()
    } catch (error) {
      if (scope.signal.aborted) return
      // 更新會話期間保留原始本文，避免重新登入後建立另一筆訂單。
      if (needsSession(error)) {
        scope.sessionExpired()
        return
      }

      const rejection = rejectedCreate(error)
      if (rejection) {
        draftFeedback.value = clearPending(attempt) ? rejection : `${rejection} 瀏覽器仍保留待確認紀錄。`
        if (error instanceof ApiError && error.code === 'INSUFFICIENT_STOCK') scope.stockChanged()
      } else {
        draftFeedback.value = '建立結果尚未確認。請使用原內容確認同一筆訂單。'
      }
    } finally {
      creating.value = false
    }
  }

  // 更換商品頁時取消當前選取，保留已經加入的品項。
  function changeCatalogPage(next: number): void {
    productId.value = null
    catalog.page.value = next
  }

  // 欄位更新集中在草稿擁有者；有待確認本文時不能改動品項。
  function updateQuantity(id: number, quantity: string): void {
    if (pendingOrder.value || scope.writeBusy()) return
    const line = lines.value.find((entry) => entry.productId === id)
    if (line) line.quantity = quantity
  }

  // 按品項識別移除，避免畫面列順序影響草稿操作。
  function removeLine(id: number): void {
    if (pendingOrder.value || scope.writeBusy()) return
    const index = lines.value.findIndex((entry) => entry.productId === id)
    if (index !== -1) lines.value.splice(index, 1)
  }

  // 重新載入工作區時立即找回該分頁原先保存的操作。
  onMounted(restorePending)

  return {
    catalog, selectableProducts, productId, lines, pendingOrder, storageBlocked, creating, draftFeedback,
    restorePending, addLine, submitOrder, updateQuantity, removeLine, changeCatalogPage,
  }
}
