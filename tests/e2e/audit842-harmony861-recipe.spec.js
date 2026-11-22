// @ts-check
// Leezr 5.0 L7.6 → L7.11 (ADR-861) — recipe on the MARKED clone (real clicks). Proves : the Fleet licence follows the
// activity declared on the position ; the remaining Platform families say where the user is (groups, record headers,
// no squeezed tabs) ; Planning / Time / Leave each have their own title, leave is a touch list on a phone ; no icon
// button without a name on the visited pages ; the SPA asks the world settings once per load and is never refused.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers.js'
import { fixtureJson, prepare, runFixture } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 L7.6+ recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l7'
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/recipe861-${name}.jpg`, type: 'jpeg', quality: 62 }) }
function watch(page) {
  const bad = []
  const world = { loads: 0, calls: 0 }
  page.on('pageerror', e => bad.push(`js ${e.message}`))
  page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400 && !r.url().includes('/login')) bad.push(`${r.status()} ${new URL(r.url()).pathname}`) })
  page.on('request', r => { if (r.url().endsWith('/api/public/world')) world.calls++ })
  page.on('load', () => { world.loads++ })

  return { bad, world }
}
const unnamedIconButtons = page => page.evaluate(() => [...document.querySelectorAll('button.v-btn--icon, a.v-btn--icon')]
  .filter(b => b.offsetParent !== null && !b.getAttribute('aria-label') && !b.getAttribute('title') && !b.getAttribute('aria-labelledby') && !(b.textContent || '').trim()).length)
const noOverflow = page => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)

let livreur = 0
test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles')
  livreur = fixtureJson(runFixture('fleet861', process.env.AUDIT_PASSWORD_FILE, 'on')).position
})
test.afterAll(() => { runFixture('fleet861', process.env.AUDIT_PASSWORD_FILE, 'off') }) // the clone goes back to the historical scope

test('Flotte : le permis suit l’activité déclarée sur le poste (clic réel, sans compte requis)', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const { bad } = watch(page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'owner@leezr.test', PASSWORD)

  await page.goto(`/company/workforce/organization/positions/${livreur}`, { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="position-header"] h1')).toContainText('Livreur')
  const driving = page.locator('[data-test="position-activity-driving"] input')
  await expect(driving).toBeChecked()
  await shot(page, 'F1-poste-conduite')

  // switch off then on again with real clicks : the server keeps what the page shows
  await driving.click()
  await expect(driving).not.toBeChecked()
  await page.reload({ waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="position-activity-driving"] input')).not.toBeChecked()
  await page.locator('[data-test="position-activity-driving"] input').click()
  await page.reload({ waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="position-activity-driving"] input')).toBeChecked()

  // the catalogue says for whom the module asks the licence
  await page.goto('/company/catalogs/fields', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await expect(page.getByText('Conduite de véhicule').first()).toBeVisible()
  await shot(page, 'F2-catalogue-pour-qui')
  expect(bad).toEqual([])
})

test('Platform : familles restantes — groupes, fiches, aucun onglet écrasé, boutons nommés', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const { bad } = watch(page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)

  for (const [path, family, test_] of [['/platform/operations/health', 'Exploitation', 'operations'], ['/platform/catalog/modules', 'Catalogue', 'catalog'],
    ['/platform/messaging/templates', 'Messagerie', 'messaging'], ['/platform/access/users', 'Accès Platform', 'access']]) {
    await page.goto(path, { waitUntil: 'networkidle' })
    await expect(page.locator('[data-test="page-eyebrow"]').first(), path).toContainText(family)
    expect(await page.title(), path).toContain(family)
    if (test_ !== 'access') await expect(page.locator(`[data-test="${test_}-groups"]`)).toBeVisible()
    expect(await unnamedIconButtons(page), `${path} unnamed`).toBe(0)
  }
  // a group leads to its first page
  await page.goto('/platform/operations/health', { waitUntil: 'networkidle' })
  await page.locator('[data-test="operations-group-consumption"]').click()
  await expect(page).toHaveURL(/\/platform\/operations\/(usage|ai)/)

  // company record : name in the header, the seven tabs fit (no scroll arrows), one strip of indicators
  await page.goto('/platform/companies/1', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="company-detail-header"] h1')).not.toBeEmpty()
  await expect(page.locator('[data-test="company-detail-tabs"] .v-slide-group__next--disabled, [data-test="company-detail-tabs"]:not(:has(.v-slide-group__next))').first()).toBeAttached()
  await expect(page.locator('[data-test="pc-stats"]')).toBeVisible()
  await shot(page, 'P1-fiche-entreprise')

  // records reachable from the catalogue keep a way back
  await page.goto('/platform/modules/logistics_fleet', { waitUntil: 'networkidle' })
  await page.locator('[data-test="page-back"]').click()
  await expect(page).toHaveURL(/\/platform\/catalog\/modules/)
  expect(bad).toEqual([])
})

test('Company : Planning, Temps et Absences ont chacun leur titre ; absences en liste tactile sur téléphone', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const { bad, world } = watch(page)
  const titles = []
  for (const width of [1440, 390]) {
    await page.context().clearCookies()
    await page.setViewportSize({ width, height: 900 })
    await loginCompany(page, 'owner@leezr.test', PASSWORD)
    for (const path of ['/company/workforce/planning', '/company/workforce/time', '/company/workforce/leave']) {
      await page.goto(path, { waitUntil: 'networkidle' })
      await page.waitForTimeout(600)
      await expect(page.locator('[data-test="page-eyebrow"]').first()).toContainText('Planning & temps')
      if (width === 1440) titles.push((await page.locator('[data-test="time-space-header"] h1').innerText()).trim())
      expect(await noOverflow(page), `${path} @${width}`).toBe(true)
      expect(await unnamedIconButtons(page), `${path} @${width} unnamed`).toBe(0)
    }
    if (width === 390) {
      await expect(page.locator('[data-test="leave-requests"] table')).toHaveCount(0) // a list, not a sideways table
      await shot(page, 'C1-absences-mobile')
    }
  }
  expect(new Set(titles).size).toBe(3)

  // ADR-861 (L7.11) : one world-settings request per full load, never refused (was 2 per load at 30/min per IP)
  expect(world.calls).toBeLessThanOrEqual(world.loads)
  expect(bad).toEqual([])
})
