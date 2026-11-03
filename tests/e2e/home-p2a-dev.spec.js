// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

/**
 * PRODUCT-UX-3 / P2-A — recette DEV de l'accueil Company orienté actions.
 * Lecture seule + navigation (aucune donnée altérée).
 */
const SHOTS = process.env.P2A_SHOTS || '/tmp/p2a-shots'
fs.mkdirSync(SHOTS, { recursive: true })
async function shot(page, name, full = true) { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full }).catch(() => {}) }

test('P2-A DEV — accueil orienté actions (À traiter / Situation / Accès) + nav + responsive', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await loginCompany(page)

  // API: authorized priorities, no salary.
  const prio = await apiRequest(page, { method: 'GET', path: '/api/workforce/dashboard/priorities' })
  console.log('priorities status:', prio.status, 'scope:', prio.json?.scope, 'count:', (prio.json?.priorities || []).length)
  const body = JSON.stringify(prio.json)
  console.log('no _cents in priorities:', !body.includes('_cents'))

  await page.goto('/company/workforce', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)

  // Action hierarchy present.
  await expect(page.getByText(/à traiter/i).first()).toBeVisible()
  // No module-card grid anymore (the old hub had 6 module cards with "Gérer" links).
  await shot(page, '01-home-desktop')

  // "Accueil" nav entry present in the sidebar.
  const homeNav = await page.locator('nav, aside').getByText(/^Accueil$/).count()
  console.log('Accueil nav entries (expected >=1):', homeNav)

  // Responsive sweep.
  for (const [w, h, name] of [[1920, 1080, '02-desktop-1920'], [1366, 768, '03-laptop-1366'], [768, 1024, '04-tablet-768'], [390, 844, '05-mobile-390']]) {
    await page.setViewportSize({ width: w, height: h })
    await page.waitForTimeout(700)
    await shot(page, name)
  }
})

test('P2-A DEV — dark mode accueil', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark' })
  const page = await ctx.newPage()
  page.setDefaultTimeout(20000)
  await loginCompany(page)
  await page.goto('/company/workforce', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await shot(page, '06-dark-home')
  await ctx.close()
})
