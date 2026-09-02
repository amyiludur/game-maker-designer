import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5273,
    // The SPA is served by Vite, not by Laravel, so /api is proxied in development. That
    // keeps the browser on one origin and means Sanctum's cookie works without CORS.
    proxy: {
      '/api': { target: process.env.GMD_API ?? 'http://127.0.0.1:8811', changeOrigin: true },
    },
  },
  test: {
    environment: 'happy-dom',
    include: ['src/**/*.spec.ts'],
  },
})
