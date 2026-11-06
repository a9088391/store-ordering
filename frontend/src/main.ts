import { createApp } from 'vue'
import App from './App.vue'
import './style.css'

// 同來源的 Blade 頁面提供唯一掛載點，Vue 接手登入與庫存畫面。
createApp(App).mount('#app')
