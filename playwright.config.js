import { defineConfig } from '@playwright/test'

// Harnais E2E de validation visuelle — LOCAL uniquement (requiert l'app servie sur leezr.test
// via Valet + les comptes de seeders dev). Non câblé dans le CI PHP (qui ne sert pas l'app).
// Lancement : pnpm test:e2e   (comptes de test : seeders dev, env local/testing — cf. ADR-568/sécurité)
// ADR-830 — une campagne E2E ÉCRIT (par l'API comme par les commandes de recette). Elle vise un clone isolé
// et marqué (ADR-827) servi par sa propre instance :
//   E2E_BASE_URL=http://127.0.0.1:8821 E2E_DB_DATABASE=leezr_recipe_x pnpm test:e2e
// E2E_DB_DATABASE est transmis aux commandes artisan lancées par les specs (DB_DATABASE + préfixe Redis dédié).
// Viser le DEV (leezr.test) exige un choix explicite : E2E_ALLOW_DEV=1.
const baseURL = process.env.E2E_BASE_URL || 'https://leezr.test'

if (/(^|\/\/)leezr\.test/.test(baseURL) && process.env.E2E_ALLOW_DEV !== '1')
  throw new Error('E2E refused on the DEV (leezr.test): run against a marked clone (E2E_BASE_URL + E2E_DB_DATABASE), or set E2E_ALLOW_DEV=1 explicitly.')

if (process.env.E2E_DB_DATABASE) {
  process.env.DB_DATABASE = process.env.E2E_DB_DATABASE
  process.env.REDIS_PREFIX = process.env.E2E_REDIS_PREFIX || `${process.env.E2E_DB_DATABASE}-`
}

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    channel: 'chrome',
    headless: true,
    viewport: { width: 1440, height: 900 },
    screenshot: 'only-on-failure',
  },
})
