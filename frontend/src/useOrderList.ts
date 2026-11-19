import { ref, shallowRef, watch } from 'vue'
import { getOrders, isPositiveSafeInteger, type OrderFilters, type OrderPage } from './api'
import { needsSession } from './workspace'

// 已套用篩選與頁碼屬於工作區，關閉清單畫面仍須保留。
export function useOrderList(storeId: number, sessionExpired: () => void) {
  const filters = shallowRef(emptyFilters())
  const filterError = ref('')
  const page = ref(1)
  const lastPage = ref(1)
  const list = shallowRef<OrderPage | null>(null)
  const listError = ref('')
  const listRefresh = ref(0)

  // 以同一組空值初始化或清除已套用的篩選條件。
  function emptyFilters(): OrderFilters {
    return { orderNumber: '', status: '', from: '', to: '' }
  }

  // 篩選只在提交時生效，換條件後從第一頁開始讀取。
  function applyFilters(fields: OrderFilters): void {
    // 單號須符合 API 的正整數契約，再更新已套用篩選。
    const id = fields.orderNumber
    if (id !== '' && (!/^[1-9][0-9]*$/.test(id) || !isPositiveSafeInteger(Number(id)))) {
      filterError.value = '請輸入有效的正整數訂單編號。'
      return
    }
    // 有效的新條件總是從第一頁開始，避免沿用舊結果的頁碼。
    filterError.value = ''
    page.value = 1
    filters.value = { ...fields }
  }

  // 取消已被篩選、換頁或重查取代的清單，避免顯示較晚完成的舊結果。
  watch([filters, page, listRefresh], async ([query, currentPage], [previousQuery], onCleanup) => {
    // 清掉過期的列；同一篩選的重新整理沿用頁碼。
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
      if (needsSession(error)) sessionExpired()
      else listError.value = '無法讀取訂單清單，請重試。'
    }
  }, { immediate: true })

  // 手動重讀與操作後更新都沿用目前的篩選及頁碼。
  function refreshList(): void {
    listRefresh.value++
  }

  return { filters, filterError, page, lastPage, list, listError, applyFilters, refreshList }
}
