// @ts-check
// ADR-639 (W-B) — HR Settings hub: aggregated sections, provenance, editable rule with persistence,
// and the old /configuration route redirects to the hub (no 404, no duplicate UI).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

// ADR-831 (D-21 bis) — since L7 (ADR-797/799) the « Temps de travail & pauses » tab is the working-time assistant
// (convention + organisation, one engine) ; the rule tables with their provenance live in its « Règles avancées »
// panel, which excludes the keys the assistant sets (the break TARGET among them). Conventions are confirmed in the
// assistant (no separate « Règles & conventions » tab). Same checks : provenance, a configurable rule edited and
// persisted, the mandatory legal rule locked.
const openAdvanced = async page => {
  await page.locator('[data-test="worktime-advanced"] .v-expansion-panel-title').click()
  await page.waitForTimeout(800)
}

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
  await expect(page.getByRole('tab', { name: /Contrats de travail/ })).toBeVisible()
  await expect(page.locator('[data-test="wt-convention"]'), 'convention confirmed in the assistant').toBeVisible()

  // Provenance: source chips present (e.g. "Loi (pays)" / "Entreprise" / "Défaut").
  await openAdvanced(page)
  await expect(page.locator('[data-test="worktime-advanced"]').getByText(/Loi \(pays\)|Entreprise|Défaut|Convention|Secteur/).first()).toBeVisible({ timeout: 8000 })
  await page.screenshot({ path: test.info().outputPath('hr-settings.png'), fullPage: false })

  // Edit a configurable rule (break maximum) and save → persists after reload.
  const value = String(80 + (Date.now() % 15))
  const row = page.locator('tr', { has: page.getByText('Durée de pause maximale', { exact: false }) }).first()
  await expect(row).toBeVisible()
  const input = row.locator('input[type="number"]')
  await input.fill(value)
  await row.getByRole('button', { name: /Enregistrer/ }).click()
  await expect(page.getByText(/Règle enregistrée/)).toBeVisible({ timeout: 8000 })

  await page.reload({ waitUntil: 'networkidle' })
  await openAdvanced(page)
  const row2 = page.locator('tr', { has: page.getByText('Durée de pause maximale', { exact: false }) }).first()
  await expect(row2.locator('input[type="number"]')).toHaveValue(value)

  // Mandatory legal rule is locked (not editable) somewhere in the tables.
  await expect(page.locator('.tabler-lock').first()).toBeVisible()

  // Dark mode, no overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
