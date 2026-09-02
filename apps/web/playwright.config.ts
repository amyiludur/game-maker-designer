import { defineConfig, devices } from '@playwright/test'

/**
 * The end-to-end smoke tests.
 *
 * They run against a real Laravel API with the Emberfall example imported, because the
 * question worth asking of this stack is whether the kernel, the API and the SPA agree —
 * and a mocked API would answer a different, easier question.
 *
 * Start the two servers first:
 *   (cd apps/api && php artisan serve --port=8811)
 *   npm run web:dev
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: process.env.GMD_WEB ?? 'http://127.0.0.1:5273',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // The mockups were drawn at 1280×880, so that is the viewport the screens are
        // checked at.
        viewport: { width: 1280, height: 880 },
        // Honours a Chromium the environment already provides, rather than downloading one
        // that matches this Playwright's pinned build. Unset locally and Playwright uses
        // its own, as usual.
        ...(process.env.GMD_CHROMIUM === undefined
          ? {}
          : { launchOptions: { executablePath: process.env.GMD_CHROMIUM } }),
      },
    },
  ],
})
