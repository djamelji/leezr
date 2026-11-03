// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

/**
 * PRODUCT-UX-3 / P2-B — recette DEV : Paramètres RH rationalisés.
 * Plus d'onglets lanceurs ni de doublon « rules » ; deep-links legacy redirigés.
 */
const SHOTS = process.env.P2B_SHOTS || '/tmp/p2b-shots'
fs.mkdirSync(SHOTS, { recursive: true })
async function shot(page, name, full = true) { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full }).catch(() => {}) }

test('P2-B DEV — settings has only real editable tabs + legacy deep-link redirects', async ({ page }) => {
  test.setTimeout(2 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await loginCompany(page)

  // API: only real sections.
  const s = await apiRequest(page, { method: 'GET', path: '/api/workforce/settings' })
  const keys = (s.json?.sections || []).map(x => x.key)
  console.log('settings sections:', JSON.stringify(keys))
  console.log('no launcher/dup sections:', !keys.some(k => ['organization', 'leave', 'planning', 'documents', 'rules'].includes(k)))

  await page.goto('/company/workforce/settings/general', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await expect(page.getByText(/paramètres rh/i).first()).toBeVisible()
  // No launcher tabs.
  for (const gone of [/organisation/i, /^congés$/i, /planning/i]) {
    await expect(page.getByRole('tab', { name: gone })).toHaveCount(0)
  }
  await shot(page, '01-settings-general')

  // Worktime tab = real editable rules (single source of truth).
  await page.getByRole('tab', { name: /temps de travail/i }).first().click()
  await page.waitForTimeout(1500)
  await shot(page, '02-settings-worktime')

  // Legacy deep-link → business space.
  await page.goto('/company/workforce/settings/organization', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await expect(page).toHaveURL(/\/organization\//)
  console.log('settings/organization redirected to:', page.url())

  await page.goto('/company/workforce/settings/leave', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await expect(page).toHaveURL(/\/workforce\/leave/)
  console.log('settings/leave redirected to:', page.url())
})

test('P2-B DEV — dark mode settings', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark' })
  const page = await ctx.newPage()
  page.setDefaultTimeout(20000)
  await loginCompany(page)
  await page.goto('/company/workforce/settings/general', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await shot(page, '03-dark-settings')
  await ctx.close()
})
