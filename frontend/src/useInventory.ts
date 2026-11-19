import { ref, shallowRef, watch, type Ref } from 'vue'
import { getInventory, type InventoryPage } from './api'
import { needsSession } from './workspace'

// 庫存表與建單商品各自保有頁碼，但共用相同的授權查詢與取消舊請求規則。
export function useInventory(storeId: number, sessionExpired: () => void, active = ref(true)) {
  const data = shallowRef<InventoryPage | null>(null)
  const failed = ref(false)
  const page = ref(1)
  const lastPage = ref(1)
  const attempt = ref(0)

  // 避免已換頁或離開畫面的讀取更新狀態。
  watch([active, page, attempt], async ([enabled, currentPage], _previous, onCleanup) => {
    // 只在需要讀取時清掉舊列；離開建單時保留最後的品項名稱供草稿顯示。
    if (!enabled) return
    data.value = null
    failed.value = false
    const controller = new AbortController()
    onCleanup(() => controller.abort())

    try {
      // 用伺服器分頁資料更新控制；舊請求無權修改目前頁。
      const result = await getInventory(storeId, currentPage, controller.signal)
      if (controller.signal.aborted) return
      lastPage.value = Math.max(1, Math.ceil(result.meta.total / result.meta.perPage))
      if (currentPage > lastPage.value) {
        page.value = lastPage.value
        return
      }
      data.value = result
    } catch (error) {
      // 過期畫面不能觸發重新確認會話。
      if (controller.signal.aborted) return
      if (needsSession(error)) sessionExpired()
      else failed.value = true
    }
  }, { immediate: true })

  // 重讀目前商品頁，不清除使用者已選取的頁碼。
  function refresh(): void {
    attempt.value++
  }

  return { data, failed, page, lastPage, refresh }
}
