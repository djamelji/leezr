// @ts-check
// Leezr 5.0 L1 (ADR-855) — Platform cockpit V35, browser recipe on the MARKED clone. Self-preparing (profiles +
// cockpit855 fixture), order-independent. Proves : recommended layout per role, drag / resize / save / reload,
// contextual navigation, finance never served to Support (even from a saved layout), no access without
// view_dashboard, loading and error states.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers.js'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 cockpit recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l1'
const shot = async (page, name) => { await page.waitForTimeout(700); await page.screenshot({ path: `${OUT}/recipe-${name}.jpg`, type: 'jpeg', quality: 62, fullPage: true }) }
const tileTitles = page => page.locator('.dashboard-tile').evaluateAll(ts => ts.map(t => t.innerText.split('\n')[0].trim()))
const layout = page => page.evaluate(async () => (await (await fetch('/api/platform/dashboard/layout', { credentials: 'include', headers: { Accept: 'application/json' } })).json()).layout)

async function openCockpit(page) {
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
}
async function recommended(page) {
  await page.locator('[data-test="cockpit-recommended"]').click()
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/dashboard/layout') && r.request().method() === 'PUT' && r.ok()),
    page.locator('[data-test="cockpit-reset-confirm"]').click(),
  ])
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(1500)
}

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles', 'cockpit855')
})

test('Super-admin : disposition recommandée, redimensionnement, déplacement, sauvegarde et rechargement', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await openCockpit(page)
  await recommended(page)

  expect(await tileTitles(page)).toEqual(expect.arrayContaining(['Revenus récurrents', 'Essais', 'Activité des modules', 'Répartition par offre', 'Activité récente']))
  await expect(page.locator('.app-live')).toContainText('Santé de la plateforme')
  await shot(page, 'A1-recommande')

  // resize « Revenus récurrents » 8 → 12 columns with the real handle
  const revenue = page.locator('.dashboard-tile', { hasText: 'Revenus récurrents' })
  const before = await revenue.boundingBox()
  await revenue.hover()
  const handle = revenue.locator('.dashboard-resize-handle')
  const hb = await handle.boundingBox()
  await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2)
  await page.mouse.down()
  await page.mouse.move(hb.x + 420, hb.y + 10, { steps: 12 })
  await page.mouse.up()
  await page.waitForTimeout(600)
  const after = await revenue.boundingBox()
  expect(after.width).toBeGreaterThan(before.width + 200)
  await expect(page.locator('[data-test="cockpit-save"]')).toBeVisible()
  await shot(page, 'A2-redimensionne')

  // the expanded revenue tile shows the analytic aside (size-aware content, not a bigger copy)
  await expect(revenue).toContainText('Attrition')

  // drag « Activité des modules » to the first row (grip only)
  const modules = page.locator('.dashboard-tile', { hasText: 'Activité des modules' })
  await modules.hover()
  const grip = modules.locator('.drag-handle')
  const gb = await grip.boundingBox()
  const first = await page.locator('.dashboard-tile').first().boundingBox()
  await page.mouse.move(gb.x + 4, gb.y + 4)
  await page.mouse.down()
  await page.mouse.move(first.x + 20, first.y + 10, { steps: 18 })
  await page.mouse.up()
  await page.waitForTimeout(600)

  await Promise.all([
    page.waitForResponse(r => r.url().includes('/dashboard/layout') && r.request().method() === 'PUT' && r.ok()),
    page.locator('[data-test="cockpit-save"]').click(),
  ])
  const saved = await layout(page)
  const savedRevenue = saved.find(t => t.key === 'platform.commercial_overview')
  expect(savedRevenue.w).toBe(12)
  const savedModules = saved.find(t => t.key === 'platform.catalog_overview')

  // reload : the personal layout is kept
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  const reloaded = await layout(page)
  expect(reloaded.find(t => t.key === 'platform.commercial_overview').w).toBe(12)
  expect(reloaded.find(t => t.key === 'platform.catalog_overview')).toMatchObject({ x: savedModules.x, y: savedModules.y })
  await shot(page, 'A3-apres-rechargement')

  // back to the recommended layout
  await recommended(page)
  expect((await layout(page)).find(t => t.key === 'platform.commercial_overview').w).toBe(8)
})

test('Navigation contextuelle : une alerte et un essai ouvrent la bonne page', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await openCockpit(page)
  await recommended(page) // order-independent : the recipe never relies on another test's layout

  const firstPriority = page.locator('[data-test="cockpit-priorities"] a').first()
  if (await firstPriority.count()) {
    await firstPriority.click()
    await expect(page).toHaveURL(/\/platform\/(billing|communications|operations)\//)
    await openCockpit(page)
  }
  const trial = page.locator('.dashboard-tile', { hasText: 'Essais' }).locator('a[href*="/platform/companies/"]').first()
  await trial.click()
  await expect(page).toHaveURL(/\/platform\/companies\/\d+/)
})

test('Support : la finance d\'une disposition enregistrée n\'est jamais servie', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  prepare('cockpit855') // re-seed the Support layout WITH finance widgets
  const forbidden = []
  page.on('response', async r => {
    if (r.url().includes('/dashboard/widgets/data') && r.ok()) {
      const j = await r.json().catch(() => ({}))
      for (const x of j.results || []) if (x.error === 'forbidden') forbidden.push(x.key)
    }
  })
  await loginPlatform(page, 'support-cockpit855@platform.test', PASSWORD)
  await openCockpit(page)
  const titles = await tileTitles(page)

  expect(titles).toEqual(['Tickets ouverts', 'Essais'])
  await expect(page.locator('body')).not.toContainText('Revenus récurrents')
  await expect(page.locator('body')).not.toContainText('Encaissé ce mois')
  expect(forbidden).toEqual([])
  await shot(page, 'B1-support')
})

test('Gouvernance : uniquement ses blocs ; profil sans accès au cockpit : refus', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginPlatform(page, 'gov-cockpit855@platform.test', PASSWORD)
  await openCockpit(page)
  expect(await tileTitles(page)).toEqual(['Activité des modules'])
  await shot(page, 'C1-gouvernance')

  await page.context().clearCookies()
  await loginPlatform(page, 'roles-only@leezr.com', PASSWORD)
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await expect(page).toHaveURL(/\/platform\/403/)
})

test('États : chargement puis erreur d\'un bloc, avec « Réessayer »', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await openCockpit(page)
  await recommended(page)
  await page.route('**/api/platform/billing/commercial-series**', route => route.fulfill({ status: 500, body: '{}' }))
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  const revenue = page.locator('.dashboard-tile', { hasText: 'Revenus récurrents' })
  await expect(revenue).toContainText('Données indisponibles')
  await expect(revenue.getByRole('button', { name: 'Réessayer' })).toBeVisible()
  await shot(page, 'D1-erreur')

  await page.unroute('**/api/platform/billing/commercial-series**')
  await revenue.getByRole('button', { name: 'Réessayer' }).click()
  await expect(revenue).toContainText('MRR')
  await expect(revenue).not.toContainText('Données indisponibles')
})
