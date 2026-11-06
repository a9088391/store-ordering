<script setup lang="ts">
import { onMounted, onUnmounted, ref, shallowRef } from 'vue'
import { ApiError, getSession, login, logout, type Session } from './api'
import InventoryView from './InventoryView.vue'

type SessionCheck = 'login' | 'logout' | 'expired'

const session = shallowRef<Session | null>(null)
const pending = ref(false)
const feedback = ref('')
const email = ref('')
const password = ref('')
const lifetime = new AbortController()
let checkReason: SessionCheck | undefined

// 重新讀取會話後，根據觀察到的身分說明下一步，不推定前次操作是否送達。
function sessionFeedback(reason: SessionCheck | undefined, authenticated: boolean): string {
  if (reason === 'login') {
    return authenticated ? '已確認登入狀態。' : '目前尚未登入，請重新輸入密碼。'
  }
  if (reason === 'logout') {
    return authenticated ? '目前仍為登入狀態，您可以再次登出。' : '已登出。'
  }
  if (reason === 'expired') {
    return authenticated ? '登入狀態已更新。' : '登入已失效，請重新登入。'
  }
  return ''
}

// 只把契約明定為拒絕的登入回應當成可直接修正的錯誤。
function rejectedLogin(error: unknown): string | undefined {
  if (!(error instanceof ApiError)) return

  if (error.status === 401 && error.code === 'INVALID_CREDENTIALS') {
    return '帳號或密碼不正確。'
  }
  if (error.status === 422 && error.code === 'VALIDATION_FAILED') {
    return '帳號或密碼格式不正確。'
  }
  if (error.status === 429 && error.code === 'LOGIN_THROTTLED') {
    return error.retryAfter > 0
      ? `登入次數過多，請在 ${error.retryAfter} 秒後再試。`
      : '登入次數過多，請稍後再試。'
  }
}

// 暫停使用舊身分和 token，直到伺服器回應目前有效的會話。
async function readSession(): Promise<void> {
  session.value = null
  feedback.value = ''

  try {
    // 已確認的身分才可開啟對應畫面。
    const current = await getSession(lifetime.signal)
    session.value = current
    feedback.value = sessionFeedback(checkReason, current.user !== null)
    checkReason = undefined
  } catch {
    // 保留需確認的原因，讓重試仍能回報前次登入或登出的結果。
    if (!lifetime.signal.aborted) {
      feedback.value = '無法確認登入狀態，請重試。'
    }
  }
}

// 初始載入、手動重試及受保護查詢失效都走同一個會話確認入口。
async function refreshSession(reason?: SessionCheck): Promise<void> {
  if (pending.value) return
  pending.value = true
  checkReason = reason ?? checkReason

  try {
    await readSession()
  } finally {
    pending.value = false
  }
}

// 每次使用者操作只送出一次；CSRF 失效或回應不明時，改為讀取目前身分。
async function changeSession(action: 'login' | 'logout'): Promise<void> {
  // 檢查會話和操作方向，避免重複提交或以過期畫面改動身分。
  const previous = session.value
  const signingIn = action === 'login'
  if (pending.value || previous === null) return
  if (signingIn !== (previous.user === null)) return

  // 保留帳號供修正，但送出後即清掉密碼；登出開始時移除舊帳號的庫存。
  pending.value = true
  feedback.value = ''
  const submittedPassword = password.value
  password.value = ''
  if (!signingIn) session.value = null

  try {
    // 成功回應帶有輪替後的 token；身分方向不符時也要重新確認。
    const current = signingIn
      ? await login({ email: email.value, password: submittedPassword }, previous.csrfToken, lifetime.signal)
      : await logout(previous.csrfToken, lifetime.signal)
    if (signingIn !== (current.user !== null)) throw new Error('會話回應與操作不符。')
    session.value = current
    if (!signingIn) feedback.value = '已登出。'
  } catch (error) {
    if (lifetime.signal.aborted) return

    // 明確拒絕可直接修正；其餘情況重新取得會話，且不重送原請求。
    const rejection = signingIn ? rejectedLogin(error) : undefined
    if (rejection !== undefined) {
      feedback.value = rejection
    } else {
      checkReason = action
      await readSession()
    }
  } finally {
    pending.value = false
  }
}

// 掛載時向 Laravel 確認身分；卸載即結束會話請求的使用期限。
onMounted(refreshSession)
onUnmounted(() => lifetime.abort())
</script>

<template>
  <main class="shell">
    <h1>門市訂單系統</h1>
    <p class="feedback" role="status">{{ feedback }}</p>

    <!-- 確認會話前不顯示登入表單或舊帳號的庫存。 -->
    <section v-if="!session" class="panel" aria-label="登入狀態">
      <p v-if="pending" role="status">正在確認登入狀態…</p>
      <button v-else type="button" @click="refreshSession()">重試</button>
    </section>

    <!-- 原生表單保留鍵盤提交、自動填寫及必填欄位提示。 -->
    <section v-else-if="!session.user" class="panel" aria-labelledby="login-heading">
      <h2 id="login-heading">登入</h2>
      <form class="login-form" @submit.prevent="changeSession('login')">
        <label for="email">帳號</label>
        <input
          id="email"
          v-model="email"
          type="email"
          autocomplete="username"
          maxlength="254"
          required
          :readonly="pending"
        >
        <label for="password">密碼</label>
        <input
          id="password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
          :readonly="pending"
        >
        <button type="submit" :disabled="pending">{{ pending ? '登入中…' : '登入' }}</button>
      </form>
    </section>

    <!-- 每次確認身分後只載入該身分的授權清單。 -->
    <InventoryView
      v-else
      :key="session.user.id"
      :role="session.user.role"
      :actor-id="session.user.id"
      :csrf-token="session.csrfToken"
      @logout="changeSession('logout')"
      @session-expired="refreshSession('expired')"
    />
  </main>
</template>
