// @ts-check
// ADR-638 (W-E2) — Platform visual admin for org presets: catalogue → create draft → build → save →
// reload (persist) → publish → immutable → duplicate (new version). Isolated per-run fixture key.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

async function pickFirstOption(page, selectLocator) {
  await selectLocator.click()
  await page.getByRole('option').first().click()
  await page.waitForTimeout(250)
}

test('platform admin builds, saves, publishes and versions an org preset visually', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  const key = 'e2e_preset_' + Date.now()

  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/organization-presets', { waitUntil: 'networkidle' })
  await expect(page.getByText("Presets d'organisation").first()).toBeVisible({ timeout: 10000 })

  // Create a draft.
  await page.getByRole('button', { name: /Nouveau preset/ }).click()
  let dlg = page.getByRole('dialog')
  await dlg.getByLabel(/Identifiant technique/).fill(key)
  await dlg.locator('input[type="text"]').nth(1).fill('E2E Preset')
  await pickFirstOption(page, dlg.locator('.v-select').nth(0)) // jobdomain
  await page.getByRole('button', { name: /Créer et éditer/ }).click()
  await expect(page).toHaveURL(/\/platform\/organization-presets\/\d+/, { timeout: 10000 })

  // Add two flat departments (hierarchy is covered by backend tests).
  for (const name of ['Direction', 'Administration']) {
    await page.getByRole('button', { name: /Ajouter un département/ }).click()
    const d = page.getByRole('dialog')
    await d.getByLabel(/Nom du département/).fill(name)
    await d.getByRole('button', { name: /^Enregistrer$/ }).click()
    await expect(page.getByText(name).first()).toBeVisible()
  }

  // Add a role.
  await page.getByRole('button', { name: /Ajouter un poste/ }).click()
  await page.getByRole('dialog').getByLabel(/Intitulé du poste/).fill('Responsable')
  await page.getByRole('dialog').getByRole('button', { name: /^Enregistrer$/ }).click()
  await expect(page.getByText('Responsable').first()).toBeVisible()
  await page.screenshot({ path: `${SCRATCH}/we2-editor.png`, fullPage: false })

  // Save (header) → reload → structure persists.
  await page.locator('.d-flex > .v-btn', { hasText: /Enregistrer/ }).first().click().catch(async () => {
    await page.getByRole('button', { name: /^Enregistrer$/ }).first().click()
  })
  await page.waitForTimeout(600)
  await page.reload({ waitUntil: 'networkidle' })
  await expect(page.getByText('Administration').first()).toBeVisible({ timeout: 10000 })
  await expect(page.getByText('Responsable').first()).toBeVisible()

  // Publish → read-only.
  await page.getByRole('button', { name: /Publier/ }).click()
  await expect(page.getByText(/lecture seule/)).toBeVisible({ timeout: 8000 })
  await expect(page.getByRole('button', { name: /Ajouter un département/ })).toHaveCount(0)

  // Duplicate → new editable version.
  await page.getByRole('button', { name: /Nouvelle version/ }).click()
  await expect(page.getByRole('button', { name: /Ajouter un département/ })).toBeVisible({ timeout: 8000 })

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
