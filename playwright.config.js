import { defineConfig } from '@playwright/test'

// Harnais E2E de validation visuelle — LOCAL uniquement (requiert l'app servie sur leezr.test
// via Valet + les comptes de seeders dev). Non câblé dans le CI PHP (qui ne sert pas l'app).
// Lancement : pnpm test:e2e   (comptes de test : seeders dev, env local/testing — cf. ADR-568/sécurité)
// ADR-830 — une campagne E2E ÉCRIT (par l'API comme par les commandes de recette). Elle vise un clone isolé
// et marqué (ADR-827) servi par sa propre instance :
//   E2E_BASE_URL=http://127.0.0.1:8821 E2E_DB_DATABASE=leezr_recipe_x pnpm test:e2e
// E2E_DB_DATABASE est transmis aux commandes artisan lancées par les specs (DB_DATABASE + préfixe Redis dédié).
// ADR-831 — aucun drapeau ne permet de viser le DEV : E2E_ALLOW_DEV est retiré, et tests/e2e/global-setup.js
// exige, avant le premier test, la preuve que l'application servie ET les commandes de recette sont sur le
// clone marqué E2E_DB_DATABASE (sonde /api/_recipe/identity lue par le serveur sur sa connexion réelle).
const baseURL = process.env.E2E_BASE_URL || 'https://leezr.test'

if (process.env.E2E_ALLOW_DEV)
  throw new Error('E2E_ALLOW_DEV was withdrawn (ADR-831): a campaign only runs against a marked clone (E2E_BASE_URL + E2E_DB_DATABASE).')
if (/(^|\/\/)leezr\.test/.test(baseURL))
  throw new Error('E2E refused on the DEV (leezr.test): run against a marked clone (E2E_BASE_URL + E2E_DB_DATABASE).')

if (process.env.E2E_DB_DATABASE) {
  process.env.DB_DATABASE = process.env.E2E_DB_DATABASE
  process.env.REDIS_PREFIX = process.env.E2E_REDIS_PREFIX || `${process.env.E2E_DB_DATABASE}-`
}

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.js',
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
