import { defineConfig } from '@playwright/test'

// Harnais E2E de validation visuelle — LOCAL uniquement (requiert l'app servie sur leezr.test
// via Valet + les comptes de seeders dev). Non câblé dans le CI PHP (qui ne sert pas l'app).
// Lancement : pnpm test:e2e   (comptes de test : seeders dev, env local/testing — cf. ADR-568/sécurité)
export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL || 'https://leezr.test',
    ignoreHTTPSErrors: true,
    channel: 'chrome',
    headless: true,
    viewport: { width: 1440, height: 900 },
    screenshot: 'only-on-failure',
  },
})
