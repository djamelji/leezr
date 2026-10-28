// @ts-check
// ADR-639 (W-B) — HR Settings hub: aggregated sections, provenance, editable rule with persistence,
// and the old /configuration route redirects to the hub (no 404, no duplicate UI).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('HR settings hub aggregates sections, shows provenance and edits a rule with persistence', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')

  // Old link redirects to the hub (no 404, no duplicate menu).
  await page.goto('/company/workforce/configuration', { waitUntil: 'networkidle' })
  await expect(page).toHaveURL(/\/company\/workforce\/settings\/worktime/, { timeout: 10000 })

  // Hub header + business-term tabs.
  await expect(page.getByText('Paramètres RH').first()).toBeVisible({ timeout: 10000 })
  await expect(page.getByRole('tab', { name: /Temps de travail/ })).toBeVisible()
  await expect(page.getByRole('tab', { name: /Règles & conventions/ })).toBeVisible()

  // Provenance: source chips present (e.g. "Loi (pays)" / "Entreprise" / "Défaut").
  await expect(page.locator('.v-chip').filter({ hasText: /Loi|Entreprise|Défaut|Convention|Secteur/ }).first()).toBeVisible({ timeout: 8000 })
  await page.screenshot({ path: `${SCRATCH}/hr-settings.png`, fullPage: false })

  // Edit a configurable rule (break target) and save → persists after reload.
  const row = page.locator('tr', { has: page.getByText('Durée de pause cible', { exact: false }) }).first()
  await expect(row).toBeVisible()
  const input = row.locator('input[type="number"]')
  await input.fill('27')
  await row.getByRole('button', { name: /Enregistrer/ }).click()
  await expect(page.getByText(/Règle enregistrée/)).toBeVisible({ timeout: 8000 })

  await page.reload({ waitUntil: 'networkidle' })
  const row2 = page.locator('tr', { has: page.getByText('Durée de pause cible', { exact: false }) }).first()
  await expect(row2.locator('input[type="number"]')).toHaveValue('27')

  // Mandatory legal rule is locked (not editable) somewhere in the tables.
  await expect(page.locator('.tabler-lock').first()).toBeVisible()

  // Dark mode, no overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
