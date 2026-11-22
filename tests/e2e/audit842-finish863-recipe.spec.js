// @ts-check
// Leezr 5.0 final touches (ADR-862, « finitions finales ») — recipe on the MARKED clone (real clicks). Proves : the
// browser tab names the two dashboards, People and the person record ; the Documents views are all reachable on a
// phone (no cut first tab) ; stat-strip labels are whole at 390 px ; the person record has the V22 page header ; the
// occasional driver is declared on the person file (switch on → persisted → switch off : the clone is left as found).
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers.js'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 final touches recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l7'
const shot = async (page, name) => { await page.waitForTimeout(500); await page.screenshot({ path: `${OUT}/recipe863-${name}.jpg`, type: 'jpeg', quality: 62 }) }
function watch(page) {
  const bad = []
  page.on('pageerror', e => bad.push(`js ${e.message}`))
  page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400 && !r.url().includes('/login')) bad.push(`${r.status()} ${new URL(r.url()).pathname}`) })

  return bad
}
const cutLabels = page => page.evaluate(() => [...document.querySelectorAll('.app-stat-strip__text, .asn-mtab__label')]
  .filter(el => el.offsetParent !== null && el.scrollWidth > el.clientWidth + 1).map(el => el.textContent.trim()))
const outside = page => page.evaluate(() => [...document.querySelectorAll('[data-test="documents-views-mobile"] [role="tab"]')]
  .filter(el => { const r = el.getBoundingClientRect(); return r.left < 0 || r.right > window.innerWidth }).length)

test.beforeAll(() => { fs.mkdirSync(OUT, { recursive: true }); prepare('profiles') })

test('Titres d’onglet : tableaux de bord, Personnes et fiche collaborateur', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const bad = watch(page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  expect(await page.title()).toBe('Vue d’ensemble · Tableau de bord · Leezr'.replace('’', "'"))

  await page.context().clearCookies()
  await loginCompany(page, 'owner@leezr.test', PASSWORD)
  await page.goto('/home', { waitUntil: 'networkidle' })
  expect(await page.title()).toMatch(/^Accueil · .+ · Leezr$/)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  expect(await page.title()).toBe('Personnes · Équipe · Leezr')
  await page.goto('/company/people/user-3', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  expect(await page.title()).toMatch(/^Bob Dupont · .+ · Leezr$/)
  await expect(page.locator('[data-test="person-header"] h1')).toHaveText('Bob Dupont')
  await expect(page.locator('[data-test="person-header"] [data-test="page-back"]')).toBeVisible()
  expect(bad).toEqual([])
})

test('Téléphone : vues Documents toutes accessibles, libellés entiers, fiche lisible', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const bad = watch(page)
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page, 'owner@leezr.test', PASSWORD)
  for (const view of ['todo', 'company', 'people', 'signatures']) {
    await page.goto(`/company/documents/${view}`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    expect(await outside(page), `${view} : every view button inside the screen`).toBe(0)
    expect(await cutLabels(page), `${view} : no truncated label`).toEqual([])
  }
  // switching views by touch
  await page.locator('[data-test="asn-mtab-company"]').click()
  await expect(page).toHaveURL(/\/company\/documents\/company$/)
  await page.goto('/company/documents/compliance', { waitUntil: 'networkidle' })
  expect(await cutLabels(page), 'compliance strip').toEqual([])
  await shot(page, 'M1-documents-telephone')
  await page.goto('/company/people/user-3', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  expect(await cutLabels(page), 'person tabs').toEqual([])
  await shot(page, 'M2-fiche-telephone')
  expect(bad).toEqual([])
})

test('Conducteur occasionnel : déclaration explicite sur la fiche (clic réel, persistée, puis retirée)', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const bad = watch(page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'owner@leezr.test', PASSWORD)
  await page.goto('/company/people/user-3/profile', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  const sw = () => page.locator('[data-test="person-activity-driving"] input')
  await expect(page.locator('[data-test="person-activities"]')).toContainText('livraisons récentes sont un indice')
  const before = await sw().isChecked()
  await sw().click()
  await page.waitForTimeout(600)
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await expect(sw()).toBeChecked({ checked: !before })
  await page.locator('[data-test="person-activities"]').scrollIntoViewIfNeeded()
  await shot(page, 'F1-activite-personne')
  await sw().click() // back to the state found
  await page.waitForTimeout(600)
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await expect(sw()).toBeChecked({ checked: before })
  expect(bad).toEqual([])
})
