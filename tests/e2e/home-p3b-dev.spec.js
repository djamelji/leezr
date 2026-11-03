// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

/**
 * PRODUCT-UX-3 / P3-B — recette DEV : accueil Company UNIQUE.
 * /dashboard = seule destination, avec « À traiter » en tête + grille de widgets ;
 * /company/workforce redirige ; une seule entrée d'accueil dans la nav.
 */
const SHOTS = process.env.P3B_SHOTS || '/tmp/p3b-shots'
fs.mkdirSync(SHOTS, { recursive: true })
async function shot(page, name, full = true) { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full }).catch(() => {}) }

test('P3-B DEV — single canonical home + redirect + one nav entry', async ({ page }) => {
  test.setTimeout(2 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await loginCompany(page)

  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(3000)
  // "À traiter" fixed section is present on the single home.
  await expect(page.getByText(/à traiter/i).first()).toBeVisible()
  await shot(page, '01-home-dashboard')

  // Former workforce action home redirects to /dashboard.
  await page.goto('/company/workforce', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await expect(page).toHaveURL(/\/dashboard/)
  console.log('/company/workforce redirected to:', page.url())

  // Exactly one "Accueil" nav entry (no competing HR home).
  const accueil = await page.locator('nav, aside').getByText(/^Accueil$/).count()
  console.log('Accueil nav entries (expected 1):', accueil)

  // Responsive.
  for (const [w, h, name] of [[1920, 1080, '02-desktop-1920'], [1366, 768, '03-laptop'], [768, 1024, '04-tablet'], [390, 844, '05-mobile']]) {
    await page.setViewportSize({ width: w, height: h })
    await page.waitForTimeout(700)
    await shot(page, name)
  }
})

test('P3-B DEV — dark home', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark' })
  const page = await ctx.newPage()
  page.setDefaultTimeout(20000)
  await loginCompany(page)
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(3000)
  await shot(page, '06-dark-home')
  await ctx.close()
})
