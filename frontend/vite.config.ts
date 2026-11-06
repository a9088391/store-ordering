import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// Laravel 由 Blade 提供 HTML；靜態資產和清單放在它預期的 build 位置。
export default defineConfig({
  plugins: [vue()],
  base: '/build/',
  build: {
    manifest: 'manifest.json',
    rolldownOptions: { input: 'src/main.ts' },
  },
})
