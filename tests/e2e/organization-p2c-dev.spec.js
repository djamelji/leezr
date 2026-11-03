// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

/**
 * PRODUCT-UX-3 / P2-C — recette DEV : Organisation unifiée (Départements/Postes/Lieux),
 * lieux déplacés depuis Planning (overlap H). Lecture seule + navigation.
 */
const SHOTS = process.env.P2C_SHOTS || '/tmp/p2c-shots'
fs.mkdirSync(SHOTS, { recursive: true })
async function shot(page, name, full = true) { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full }).catch(() => {}) }

test('P2-C DEV — Organisation has Départements/Postes/Lieux + execution note', async ({ page }) => {
  test.setTimeout(2 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await loginCompany(page)

  await page.goto('/company/workforce/organization/departments', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await expect(page.getByRole('tab', { name: /départements/i }).first()).toBeVisible()
  await expect(page.getByRole('tab', { name: /postes/i }).first()).toBeVisible()
  await expect(page.getByRole('tab', { name: /lieux/i }).first()).toBeVisible()
  await shot(page, '01-org-departments')

  // Lieux tab = locations managed here now + honest execution note.
  await page.getByRole('tab', { name: /lieux/i }).first().click()
  await page.waitForTimeout(2000)
  await expect(page.getByText(/lieu d.?exécution des shifts/i).first()).toBeVisible()
  await shot(page, '02-org-locations')

  // Planning (in Temps de travail) no longer has a Lieux tab.
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  const planningLieux = await page.getByRole('tab', { name: /^lieux$/i }).count()
  console.log('Lieux tabs inside planning (expected 0):', planningLieux)
  await shot(page, '03-planning-no-locations-tab')

  // Responsive.
  await page.goto('/company/workforce/organization/locations', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2000)
  for (const [w, h, name] of [[1366, 768, '04-laptop'], [390, 844, '05-mobile']]) {
    await page.setViewportSize({ width: w, height: h })
    await page.waitForTimeout(700)
    await shot(page, name)
  }
})

test('P2-C DEV — dark mode organisation', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark' })
  const page = await ctx.newPage()
  page.setDefaultTimeout(20000)
  await loginCompany(page)
  await page.goto('/company/workforce/organization/locations', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await shot(page, '06-dark-org-locations')
  await ctx.close()
})
