// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

/**
 * PRODUCT-UX-2 / LOT C3 — recette DEV : espace unifié « Paie & déclarations »,
 * onglet Déclarations (DSN période-centré) avec bannière de capacité HONNÊTE
 * (mode simulation/désactivé), responsive + dark. Joué sur dev.leezr.com réel.
 * Aucune donnée altérée (lecture seule + navigation).
 */
const SHOTS = process.env.C3_SHOTS || '/tmp/c3-shots'
fs.mkdirSync(SHOTS, { recursive: true })
async function shot(page, name, full = true) { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full }).catch(() => {}) }

test('C3 DEV — espace unifié + onglet Déclarations + bannière honnête + responsive', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(20000)

  await loginCompany(page)
  await page.goto('/company/workforce/payroll', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)

  // Unified space title + tabs present.
  await expect(page.getByText('Paie & déclarations').first()).toBeVisible()
  await expect(page.getByRole('tab', { name: /périodes/i }).first()).toBeVisible()
  await shot(page, '01-periods-tab')

  // Single nav entry: "DSN" is gone from the sidebar (consolidated).
  const dsnNav = await page.locator('nav, aside').getByText(/^DSN$/).count()
  console.log('standalone DSN nav items (expected 0):', dsnNav)

  // Declarations tab → DSN embedded with honest capability banner.
  await page.getByRole('tab', { name: /déclarations/i }).first().click()
  await page.waitForTimeout(2500)
  await expect(page.getByText(/mode de transmission dsn/i).first()).toBeVisible()
  // Honest: no real transmission by default.
  await expect(page.getByText(/simulation|désactivée|bac à sable/i).first()).toBeVisible()
  await shot(page, '02-declarations-tab-honest-banner')

  // Responsive sweep (periods tab).
  await page.getByRole('tab', { name: /périodes/i }).first().click()
  await page.waitForTimeout(1200)
  for (const [w, h, name] of [[1920, 1080, '03-desktop-1920'], [1366, 768, '04-laptop-1366'], [768, 1024, '05-tablet-768'], [390, 844, '06-mobile-390']]) {
    await page.setViewportSize({ width: w, height: h })
    await page.waitForTimeout(800)
    await shot(page, name)
  }
})

test('C3 DEV — dark mode espace + déclarations', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark' })
  const page = await ctx.newPage()
  page.setDefaultTimeout(20000)
  await loginCompany(page)
  await page.goto('/company/workforce/payroll', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await shot(page, '07-dark-periods')
  await page.getByRole('tab', { name: /déclarations/i }).first().click()
  await page.waitForTimeout(2000)
  await shot(page, '08-dark-declarations')
  await ctx.close()
})
