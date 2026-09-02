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
    // Bound explicitly rather than left to resolve `localhost`, which on some hosts answers
    // ::1 before 127.0.0.1 — a dev server on IPv6 loopback while everything else dials IPv4
    // looks exactly like a server that never started. One address, in the dev loop and in CI.
    host: '127.0.0.1',
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
