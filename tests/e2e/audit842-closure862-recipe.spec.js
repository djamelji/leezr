// @ts-check
// Leezr 5.0 L7 closure (ADR-862) — recipe on the MARKED clone (real clicks). Proves : the Planning header no longer
// squeezes its description ; the Documents compliance / templates / signatures views are readable on desktop and on a
// phone (no sideways overflow, named buttons, no raw codes) ; browser-tab names follow the page, also after an in-app
// navigation ; the cockpit says « Facturé ce mois (HT) », never « Encaissé ».
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers.js'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 L7 closure recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l7'
const shot = async (page, name) => { await page.waitForTimeout(500); await page.screenshot({ path: `${OUT}/recipe862-${name}.jpg`, type: 'jpeg', quality: 62 }) }
function watch(page) {
  const bad = []
  page.on('pageerror', e => bad.push(`js ${e.message}`))
  page.on('console', m => { if (m.type() === 'error' && /before initialization|is not defined/.test(m.text())) bad.push(`console ${m.text()}`) })
  page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400 && !r.url().includes('/login')) bad.push(`${r.status()} ${new URL(r.url()).pathname}`) })

  return bad
}
const unnamed = page => page.evaluate(() => [...document.querySelectorAll('button.v-btn--icon, a.v-btn--icon')]
  .filter(b => b.offsetParent !== null && !b.getAttribute('aria-label') && !b.getAttribute('title') && !b.getAttribute('aria-labelledby') && !(b.textContent || '').trim()).length)
const noOverflow = page => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)

test.beforeAll(() => { fs.mkdirSync(OUT, { recursive: true }); prepare('profiles') })

test('Planning : trois actions d’en-tête, la phrase tient ; Exporter et Préférences dans « ⋯ »', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const bad = watch(page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'owner@leezr.test', PASSWORD)
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  const header = page.locator('[data-test="time-space-header"]')
  const controls = header.locator('.app-page-header__actions .v-btn:visible')
  expect(await controls.count(), 'header controls').toBeLessThanOrEqual(3)
  const desc = await header.locator('.app-page-header__description').boundingBox()
  expect(desc?.height ?? 99, 'the sentence fits on one line').toBeLessThan(30)
  await page.locator('[data-test="planning-actions-more"]').click()
  await expect(page.locator('.v-overlay--active [data-test="time-export-open"]')).toBeVisible()
  await expect(page.locator('.v-overlay--active [data-test="space-preferences"]')).toBeVisible()
  await shot(page, 'P1-planning-menu')
  await page.keyboard.press('Escape')

  // in-app navigation : the tab name follows the page (was : kept the previous page's name)
  expect(await page.title()).toContain('Planning')
  await page.locator('.layout-vertical-nav a[href="/company/documents"], .layout-vertical-nav a[href^="/company/documents/"]').first().click()
  await page.waitForURL(/\/company\/documents\//)
  await page.waitForTimeout(800)
  expect(await page.title(), 'no stale « Planning » tab name').not.toContain('Planning')
  expect(bad).toEqual([])
})

test('Documents : conformité, modèles et signatures lisibles sur desktop et téléphone', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const bad = watch(page)
  for (const width of [1440, 390]) {
    await page.context().clearCookies()
    await page.setViewportSize({ width, height: 900 })
    await loginCompany(page, 'owner@leezr.test', PASSWORD)

    await page.goto('/company/documents/compliance', { waitUntil: 'networkidle' })
    await expect(page.locator('[data-test="documents-compliance"] .app-stat-strip')).toBeVisible()
    await expect(page.locator('[data-test="documents-compliance"]')).not.toContainText(/\w+: \d/) // no « Conformes: 3 » chips
    await expect(page.locator('[data-test="documents-compliance"]')).not.toContainText('Membre')
    if (width === 390) await expect(page.locator('[data-test="compliance-list"]')).toBeVisible()
    expect(await noOverflow(page), `compliance @${width}`).toBe(true)
    expect(await unnamed(page), `compliance @${width} unnamed`).toBe(0)
    await shot(page, `D1-conformite-${width}`)

    await page.goto('/company/documents/templates', { waitUntil: 'networkidle' })
    await expect(page.locator('[data-test="contract-templates"]')).toBeVisible()
    await expect(page.locator('[data-test="contract-templates"]')).not.toContainText(/\bcdi\b/) // the type in words
    await expect(page.locator('.v-alert', { hasText: 'non validés juridiquement' })).toHaveCount(0) // sentence, not banner
    expect(await noOverflow(page), `templates @${width}`).toBe(true)
    expect(await unnamed(page), `templates @${width} unnamed`).toBe(0)
    await shot(page, `D2-modeles-${width}`)

    await page.goto('/company/documents/signatures', { waitUntil: 'networkidle' })
    await page.waitForTimeout(800)
    await expect(page.locator('.layout-page-content')).not.toContainText(/\binternal\b/)
    await expect(page.locator('.layout-page-content')).not.toContainText('Certs (')
    if (width === 390) await expect(page.locator('[data-test="signature-list"]')).toBeVisible()
    expect(await noOverflow(page), `signatures @${width}`).toBe(true)
    expect(await unnamed(page), `signatures @${width} unnamed`).toBe(0)
    await shot(page, `D3-signatures-${width}`)
  }
  expect(bad).toEqual([])
})

test('Platform : titres d’onglet justes, cockpit « Facturé ce mois (HT) »', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const bad = watch(page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="cockpit-invoiced-mtd"]')).toContainText('Facturé ce mois (HT)')
  await expect(page.locator('[data-test="cockpit-invoiced-mtd"]')).not.toContainText('Encaissé ce mois')

  await page.goto('/platform/companies', { waitUntil: 'networkidle' })
  expect(await page.title()).not.toMatch(/(\S+) · \1 ·/) // no « Entreprises · Entreprises »
  await page.goto('/platform/jobdomains/1/general', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  expect(await page.title(), 'the JobDomain record names its tab').toMatch(/Logistique · .+ · Catalogue · Leezr/)
  expect(bad).toEqual([])
})
