// @ts-check
import { expect, test } from '@playwright/test'

// Smoke E2E de validation visuelle des parcours existants (Platform / Company / Employee).
// Vérifie : login + redirection, rendu sans erreur console/JS, aucune clé i18n brute visible.
// Comptes : seeders dev (local/testing uniquement). NB : harnais LOCAL, non exécuté en CI PHP.

const I18N_NS = [
  'coupons', 'workforceMe', 'common', 'roles', 'runtime', 'platformSettings',
  'companyBilling', 'documents', 'payroll', 'dsn', 'leaves', 'planning', 'employees',
]
const RAW_KEY = new RegExp(`\\b(${I18N_NS.join('|')})\\.[a-zA-Z][a-zA-Z0-9_.]{2,}`, 'g')

async function login(page, path, email, password) {
  await page.goto(path, { waitUntil: 'networkidle' })
  await page.fill('input[type="email"], input[name="email"]', email)
  await page.fill('input[type="password"], input[name="password"]', password)
  await page.keyboard.press('Enter')
  await page.waitForTimeout(3500)
}

async function assertScreenClean(page, path) {
  const consoleErrors = []
  const onErr = m => { if (m.type() === 'error') consoleErrors.push(m.text()) }
  page.on('console', onErr)
  await page.goto(path, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const body = await page.evaluate(() => document.body.innerText || '')
  const rawKeys = [...new Set(body.match(RAW_KEY) || [])]
  page.off('console', onErr)
  expect(rawKeys, `clés i18n brutes visibles sur ${path}`).toEqual([])
  expect(consoleErrors.filter(e => !e.includes('favicon')), `erreurs console sur ${path}`).toEqual([])
}

test.describe('Company + Employee', () => {
  test.beforeEach(async ({ page }) => { await login(page, '/login', 'owner@leezr.test', 'password') })

  for (const path of ['/dashboard', '/billing', '/members', '/modules', '/documents',
    '/workforce', '/workforce/employees', '/workforce/time', '/workforce/leave',
    '/workforce/payroll', '/workforce/dsn', '/workforce/me']) {
    test(`écran rendu proprement: ${path}`, async ({ page }) => { await assertScreenClean(page, path) })
  }
})

test.describe('Platform', () => {
  test.beforeEach(async ({ page }) => { await login(page, '/platform/login', 'admin@leezr.com', 'password') })

  for (const path of ['/platform/companies', '/platform/billing/coupons', '/platform/jobdomains',
    '/platform/markets', '/platform/catalog', '/platform/documentation']) {
    test(`écran rendu proprement: ${path}`, async ({ page }) => { await assertScreenClean(page, path) })
  }
})

test('RBAC: un user non-admin ne voit pas la gestion des membres', async ({ page }) => {
  await login(page, '/login', 'bob@leezr.test', 'password')
  await page.goto('/members', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const body = await page.evaluate(() => document.body.innerText || '')
  // route gardée → page 404 (la gestion n'est pas exposée), pas de bouton d'invitation
  expect(await page.locator('button:has-text("Inviter"), button:has-text("Invite")').count()).toBe(0)
})

test('Responsive mobile: pas de débordement horizontal sur le dashboard', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, ignoreHTTPSErrors: true })
  const page = await ctx.newPage()
  await login(page, '/login', 'owner@leezr.test', 'password')
  await page.goto('/dashboard', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(2)
  await ctx.close()
})
