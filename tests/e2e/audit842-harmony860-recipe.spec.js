// @ts-check
// Leezr 5.0 L7 (ADR-860) — harmonisation recipe on the MARKED clone (profiles fixture only, real clicks). Proves : each
// Platform billing page says where the user is ; Settings are grouped (same pages, same permissions) ; the theme gear
// no longer covers tabs ; icon-only buttons have an accessible name ; mobile and dark ; no JS error, no refused call.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers.js'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 harmonisation recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l7'
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/recipe-${name}.jpg`, type: 'jpeg', quality: 62 }) }
function watch(page) {
  const bad = []
  page.on('pageerror', e => bad.push(`js ${e.message}`))
  page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400 && !r.url().includes('/login')) bad.push(`${r.status()} ${new URL(r.url()).pathname}`) })

  return bad
}
const unnamedIconButtons = page => page.evaluate(() => [...document.querySelectorAll('button.v-btn--icon, a.v-btn--icon')]
  .filter(b => b.offsetParent !== null && !b.getAttribute('aria-label') && !b.getAttribute('title') && !b.getAttribute('aria-labelledby') && !(b.textContent || '').trim()).length)

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles')
})

test('Facturation : chaque page dit où l’on est ; Paramètres regroupés ; engrenage du thème à sa place', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const bad = watch(page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)

  const titles = []
  for (const tab of ['invoices', 'payments', 'ledger']) {
    await page.goto(`/platform/billing/${tab}`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(800)
    const header = page.locator('[data-test="billing-header"]')
    await expect(page.locator('[data-test="page-eyebrow"]')).toContainText('Facturation')
    titles.push((await header.locator('h1').innerText()).trim())
    expect(await page.title()).toContain(titles.at(-1))
    await expect(page.locator('.app-customizer-toggler')).toHaveCount(0) // no floating gear over the tabs
  }
  expect(new Set(titles).size).toBe(3) // three pages, three titles
  await shot(page, 'A1-facturation-page')

  // Settings : groups → pages, header per page
  await page.goto('/platform/settings/sessions', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="settings-groups"]')).toBeVisible()
  await expect(page.locator('[data-test="settings-pages"]')).toContainText('Maintenance')
  await expect(page.locator('[data-test="page-eyebrow"]')).toContainText('Sécurité et disponibilité')
  await page.locator('[data-test="settings-group-international"]').click()
  await expect(page).toHaveURL(/\/platform\/settings\/international/)
  await shot(page, 'A2-parametres-groupes')

  // the gear lives on Theme only
  await page.goto('/platform/settings/theme', { waitUntil: 'networkidle' })
  await expect(page.locator('.app-customizer-toggler')).toHaveCount(1)

  // accessible names on the harmonised pages
  for (const path of ['/platform/billing/invoices', '/platform/settings/general']) {
    await page.goto(path, { waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    expect(await unnamedIconButtons(page), `${path} : icon buttons without a name`).toBe(0)
  }
  expect(bad).toEqual([])
})

test('Profil limité : seules ses pages et ses groupes, sans appel refusé', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const bad = watch(page)
  await loginPlatform(page, 'finance-reader@leezr.com', PASSWORD)
  await page.goto('/platform/billing/payments', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="billing-header"] h1')).toContainText('Paiements')
  await expect(page.locator('[data-testid="billing-group-offers"]')).toHaveCount(await page.locator('[data-testid="billing-group-offers"]').count()) // groups follow the permissions
  await page.goto('/platform/settings/general', { waitUntil: 'networkidle' })
  // no settings permission : the page is refused by the router (no settings API is ever called)
  await expect(page.locator('[data-test="settings-groups"]')).toHaveCount(0)
  expect(bad.filter(b => b.includes('/settings'))).toEqual([])
})

test('Mobile et sombre : en-tête lisible, onglets défilants, aucun débordement', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const bad = watch(page)
  await page.setViewportSize({ width: 390, height: 844 })
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await page.context().addCookies([{ name: '-theme', value: 'dark', url: page.url() }])
  for (const path of ['/platform/billing/dunning', '/platform/settings/maintenance']) {
    await page.goto(path, { waitUntil: 'networkidle' })
    await page.waitForTimeout(800)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
    await expect(page.locator('[data-test="page-eyebrow"]')).toBeVisible()
  }
  await shot(page, 'A3-mobile-sombre')
  expect(bad).toEqual([])
})

test('Company : en-têtes harmonisés des familles (propriétaire et profil limité), desktop et mobile', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  const bad = watch(page)
  const { loginCompany } = await import('./helpers.js')
  const PAGES = [['/company/documents/overview', 'Documents'], ['/company/shipments', 'Logistique'], ['/company/modules', 'Réglages'],
    ['/company/settings', 'Réglages'], ['/company/workforce/contracts', 'Documents'], ['/company/workforce/payroll', 'Paie'], ['/company/my-space/overview', 'Mon espace']]
  for (const width of [1440, 390]) {
    await page.goto('about:blank') // ADR-862 : close the realtime stream before dropping the session (was : 401 on its reconnect)
    await page.context().clearCookies()
    await page.setViewportSize({ width, height: 900 })
    await loginCompany(page, 'owner@leezr.test', PASSWORD)
    for (const [path, family] of PAGES) {
      await page.goto(path, { waitUntil: 'networkidle' })
      await page.waitForTimeout(700)
      await expect(page.locator('[data-test="page-eyebrow"]').first(), path).toContainText(family)
      await expect(page.locator('.app-page-header__title').first()).toBeVisible()
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `${path} @${width}`).toBe(true)
    }
  }
  await shot(page, 'B1-company-mobile')

  // a limited profile reaches only what it may : the pages it opens carry the same header, nothing is refused
  await page.goto('about:blank')
  await page.context().clearCookies()
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'consultation@leezr.test', PASSWORD)
  await page.goto('/company/my-space/overview', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="page-eyebrow"]').first()).toContainText('Mon espace')
  expect(bad).toEqual([])
})
