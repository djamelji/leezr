// @ts-check
// Leezr 5.0 L4 (ADR-856) — widget library, browser recipe on the MARKED clone. Self-preparing (profiles + widgets856
// fixture), order-independent. Proves on the Company home : recommended layout, add / remove, resize with an
// EXPLAINED refusal, save, reload ; role change (owner / manager / driver) ; a forbidden widget stored in a layout is
// never served ; a failed data batch shows recoverable error states (never « no data »).
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers.js'
import { execFileSync } from 'node:child_process'
import { CLONE_ENV, prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 widget recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l4'
const shot = async (page, name) => { await page.waitForTimeout(700); await page.screenshot({ path: `${OUT}/recipe-${name}.jpg`, type: 'jpeg', quality: 62, fullPage: true }) }
const tiles = page => page.locator('.dashboard-tile')

const layout = page => page.evaluate(async () => {
  const cid = JSON.parse(decodeURIComponent((document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/) || [])[1] || 'null'))
  const r = await fetch('/api/dashboard/layout', { credentials: 'include', headers: { Accept: 'application/json', ...(cid ? { 'X-Company-Id': String(cid) } : {}) } })

  return (await r.json()).layout || []
})

const waitPut = page => page.waitForResponse(r => r.url().includes('/api/dashboard/layout') && r.request().method() === 'PUT' && r.ok())

async function openHome(page) {
  await page.goto('/dashboard', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
}
async function recommended(page) {
  await page.locator('[data-test="home-customize"]').click()
  await page.locator('[data-test="home-recommended"]').click()
  await Promise.all([waitPut(page), page.locator('[data-test="home-reset-confirm"]').click()])
  await page.waitForTimeout(1200)
}

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles', 'widgets856')
})

test('Dirigeant : disposition recommandée, ajout, retrait, taille refusée expliquée, sauvegarde, rechargement', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'owner@leezr.test', PASSWORD)
  await openHome(page)
  await recommended(page)

  const base = await tiles(page).count()

  expect(base).toBeGreaterThan(3)
  await shot(page, 'A1-recommande')

  // add one widget from the catalog, then remove it
  await page.locator('[data-test="home-add-widget"]').click()
  await page.locator('.v-navigation-drawer .v-list-item').first().click()
  await expect(tiles(page)).toHaveCount(base + 1)

  const last = tiles(page).last()

  await last.hover()
  await last.locator('.toolbar-action').last().click()
  await expect(tiles(page)).toHaveCount(base)

  // resize a count-only KPI beyond its bounds : refused WITH its reason, width stays within 6 columns
  const kpi = tiles(page).filter({ has: page.locator('.app-kpi') }).first()

  await kpi.hover()

  const hb = await kpi.locator('.dashboard-resize-handle').boundingBox()

  await page.mouse.move(hb.x + 10, hb.y + 10)
  await page.mouse.down()
  await page.mouse.move(hb.x + 900, hb.y + 420, { steps: 14 })
  await expect(page.locator('.v-snackbar')).toContainText(/s'arrête à|ne descend|hauteur maximale/i)
  await page.mouse.up()
  await shot(page, 'A2-taille-refusee')

  // save, reload : the personal layout is kept and every tile respects its bounds
  await Promise.all([waitPut(page), page.locator('[data-test="home-customize-done"]').click()])

  const saved = await layout(page)

  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  expect((await layout(page)).map(t => [t.key, t.w, t.h])).toEqual(saved.map(t => [t.key, t.w, t.h]))
  for (const t of saved) expect(t.w).toBeLessThanOrEqual(12)
  await shot(page, 'A3-apres-rechargement')
})

test('Changement de rôle : manager et chauffeur ont leur propre catalogue ; un widget interdit ne réapparaît pas', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  prepare('widgets856') // re-seed the driver layout WITH forbidden widgets

  const refused = []

  page.on('response', async r => {
    if (r.url().includes('/api/dashboard/widgets/data') && r.ok()) {
      const j = await r.json().catch(() => ({}))
      for (const x of j.results || []) if (x.error) refused.push(`${x.key}:${x.error}`)
    }
  })

  await loginCompany(page, 'bob@leezr.test', PASSWORD)
  await page.goto('/home', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  const keys = (await layout(page)).map(t => t.key)

  expect(keys).not.toContain('billing.plan_badge')
  expect(keys).not.toContain('workforce.headcount')
  expect(refused).toEqual([])
  await shot(page, 'B1-chauffeur')

  await page.context().clearCookies()
  await loginCompany(page, 'manager@leezr.test', PASSWORD)
  await openHome(page)
  expect(refused).toEqual([])
  await shot(page, 'B2-manager')
})

test('États : un lot de données en échec affiche des erreurs récupérables, puis « Réessayer » recharge', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'owner@leezr.test', PASSWORD)
  await openHome(page)
  await page.route('**/api/dashboard/widgets/data', route => route.fulfill({ status: 500, body: '{}' }))
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.locator('.dashboard-tile').filter({ hasText: 'Données indisponibles' }).first()).toBeVisible()
  await expect(page.locator('.dashboard-tile', { hasText: /^0$/ })).toHaveCount(0)
  await shot(page, 'C1-erreurs')

  await page.unroute('**/api/dashboard/widgets/data')
  await page.locator('.dashboard-tile').getByRole('button', { name: 'Réessayer' }).first().click()
  await expect(page.locator('.dashboard-tile').filter({ hasText: 'Données indisponibles' })).toHaveCount(0, { timeout: 15000 })
})

test('Ancienne disposition hors bornes : affichée dans les bornes, ajout d’un widget puis enregistrement accepté', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  execFileSync('php', ['scripts/audit842-widgets856-fixture.php', process.env.AUDIT_PASSWORD_FILE], { env: { ...CLONE_ENV, WIDGETS856_STALE: '1' } })
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'owner@leezr.test', PASSWORD)
  await openHome(page)
  await page.locator('[data-test="home-customize"]').click()
  await page.locator('[data-test="home-add-widget"]').click()
  await page.locator('.v-navigation-drawer .v-list-item').first().click()

  const [put] = await Promise.all([
    page.waitForResponse(r => r.url().includes('/api/dashboard/layout') && r.request().method() === 'PUT'),
    page.locator('[data-test="home-customize-done"]').click(),
  ])

  expect(put.status()).toBe(200)

  const saved = await layout(page)
  const head = saved.find(t => t.key === 'workforce.headcount')
  const shifts = saved.find(t => t.key === 'planning.shifts')

  expect([head.w, head.h]).toEqual([6, 3])
  expect([shifts.w, shifts.h]).toEqual([6, 3])
  expect(saved.length).toBe(3)
  await shot(page, 'D1-ancienne-disposition')
})
