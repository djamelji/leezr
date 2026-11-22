// @ts-check
// Leezr 4.2 M4 (ADR-841) — browser recipe on the MARKED clone (global-setup, ADR-831).
// Expected amounts : scripts/audit842-m4-expected.php (raw SQL, independent of the application code) → M4_EXPECTED file.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'
import { m4Expected, prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M4 recipe only')
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''

// ADR-850 (M10) : expected amounts computed AFTER the recipe's own preparation (raw SQL, independent of the app code)
let EXPECTED = {}
const OUT = 'docs/bmad/leezr-4-2-audit/m4'
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 }) }
const cents = text => Number(String(text).replace(/[^\d]/g, '')) // « 362,83 € » → 36283

function watch(page) {
  const problems = []
  page.on('pageerror', e => problems.push(`js ${e.message}`))
  page.on('response', r => {
    if (r.status() >= 400 && r.url().includes('/api/') && !r.url().includes('/realtime/') && !(r.status() === 429 && r.url().includes('/api/public/')))
      problems.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')}`)
  })

  return problems
}

const open = async (page, path) => { await page.goto(path, { waitUntil: 'networkidle' }); await page.waitForTimeout(1500) }
// a KPI card (h4) or, since L7.7 (ADR-861), a cell of the shared stat strip : the value next to the labelled testid
const cardValue = (page, testid) => page.getByTestId(testid).locator('xpath=ancestor::*[contains(@class,"app-stat-strip__cell") or contains(@class,"v-card")][1]').locator('.app-stat-strip__value, h4').first()


test.beforeAll(() => {
  prepare('profiles', 'm1')
  EXPECTED = m4Expected()
})
test.beforeEach(({ page }) => { page.setDefaultTimeout(15000); fs.mkdirSync(OUT, { recursive: true }) })

let activeBilling = 0
test('M4-A super-admin : un seul MRR sur le tableau de bord, la Facturation et la liste ; facturé du mois juste', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const problems = watch(page)
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)

  // Billing
  await open(page, '/platform/billing/overview')
  await expect(page.getByTestId('kpi-mrr')).toContainText('Revenu récurrent mensuel (MRR, HT)')
  expect(cents(await cardValue(page, 'kpi-mrr').innerText()), 'Billing MRR = independent SQL').toBe(EXPECTED.mrr_cents)
  expect(cents(await cardValue(page, 'kpi-arr').innerText()), 'ARR = 12 × MRR').toBe(EXPECTED.arr_cents)
  expect(cents(await cardValue(page, 'kpi-addons').innerText())).toBe(EXPECTED.addons_cents)
  await shot(page, 'A0-facturation-indicateurs')
  activeBilling = cents(await cardValue(page, 'kpi-active').innerText())
  await page.getByTestId('kpi-mrr').locator('xpath=..').getByTestId('metric-help').hover()
  await expect(page.locator('.v-overlay__content').filter({ hasText: 'Exclus : essais' }).first()).toBeVisible()
  await shot(page, 'A1-facturation-mrr-aide')

  // Dashboard — the V35 cockpit (ADR-855) ; same business checks as M4 (ADR-841), anchored on stable hooks (ADR-862)
  await open(page, '/platform/dashboard/overview')
  const body = await page.locator('.layout-page-content').innerText()
  // the cockpit rounds to the euro (presentation only) : 362,83 € → « 363 € »
  const euros = text => Math.round(cents(text) / (text.includes(',') ? 100 : 1)) * 100
  const mrrShown = await page.locator('[data-test="cockpit-mrr"]').innerText()
  expect(euros(mrrShown), 'Dashboard MRR = the same MRR (rounded to the euro)').toBe(Math.round(EXPECTED.mrr_cents / 100) * 100)
  const mtd = page.locator('[data-test="cockpit-invoiced-mtd"]')
  await expect(mtd, 'the invoiced-this-month tile is on the dashboard').toContainText('Facturé ce mois (HT)')
  await expect(mtd, 'invoiced, never « collected » (ADR-862)').not.toContainText('Encaiss')
  await expect.poll(async () => euros(await mtd.locator('.app-kpi__value').innerText()), { message: 'invoiced this month = ledger (no × 100), rounded to the euro' })
    .toBe(Math.round(EXPECTED.revenue_month_cents / 100) * 100) // the tile counts up : read the settled value
  expect(body).not.toContain("Chiffre d'affaires du mois")
  const subsShown = Number(await page.locator('[data-test="cockpit-active-subscriptions"]').innerText())
  expect(subsShown, 'same subscription count on the dashboard and in Billing').toBe(activeBilling)
  await shot(page, 'A2-tableau-de-bord')

  // Companies list
  await open(page, '/platform/companies')
  expect(cents(await cardValue(page, 'companies-total-mrr').innerText()), 'Companies MRR = the same MRR (was 261 €-like public-price sum)').toBe(EXPECTED.mrr_cents)
  await expect(page.getByText('Essai').first()).toBeVisible()
  await shot(page, 'A3-liste-societes')
  expect(problems).toEqual([])
})

test('M4-B Finance (view_billing) : indicateurs visibles, liste des sociétés refusée', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const problems = watch(page)
  await loginPlatform(page, 'finance-reader@leezr.com', PASSWORD)
  await open(page, '/platform/billing/overview')
  expect(cents(await cardValue(page, 'kpi-mrr').innerText())).toBe(EXPECTED.mrr_cents)
  await shot(page, 'B-finance-facturation')
  await open(page, '/platform/companies')
  await expect(page.getByTestId('platform-forbidden')).toBeVisible()
  expect(problems).toEqual([])
})

test('M4-C lecture sociétés sans droit financier : aucune donnée financière, ni à l’écran ni dans l’API', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const problems = watch(page)
  await loginPlatform(page, 'viewer842@platform.test', PASSWORD)
  await open(page, '/platform/companies')
  await expect(page.getByTestId('companies-total-mrr')).toHaveCount(0)
  await expect(page.getByRole('columnheader', { name: /Récurrent/ })).toHaveCount(0)
  const stats = await page.evaluate(async () => (await (await fetch('/api/platform/companies', { headers: { Accept: 'application/json' }, credentials: 'include' })).json()).stats)
  expect(stats.total_mrr, 'API : no financial total').toBeNull()
  const billing = await page.evaluate(async () => (await fetch('/api/platform/billing/metrics', { headers: { Accept: 'application/json' }, credentials: 'include' })).status)
  expect(billing).toBe(403)
  await shot(page, 'C-lecteur-sans-finance')
  expect(problems.filter(p => p !== '403 GET /api/platform/billing/metrics')).toEqual([])
})
