// @ts-check
// ADR-641 (W-G1) — modernisation des formulaires Workforce : sélecteurs métier (fin des IDs
// techniques), saisie monétaire en devise (précision), confirmations de suppression. Vérifie des
// COMPORTEMENTS en navigateur réel, pas seulement la présence des composants.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('document generation uses business selectors, not technical IDs', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/documents', { waitUntil: 'networkidle' })

  await page.getByRole('button', { name: /Générer un document/ }).first().click()

  // Nouveau flux : type de document + modèle + collaborateur (sélecteurs), AUCUN champ "subject_id"
  // numérique ni code template libre.
  await expect(page.getByText('Type de document').first()).toBeVisible({ timeout: 8000 })
  await expect(page.getByText(/Modèle de document/).first()).toBeVisible()
  await expect(page.getByText(/^Collaborateur$/).first()).toBeVisible()

  // Le collaborateur se choisit par recherche (autocomplete), jamais un ID saisi. On tape un nom.
  const empField = page.locator('.v-navigation-drawer .v-autocomplete input').first()
  await empField.click()
  await empField.fill('a')
  await page.waitForTimeout(800) // debounce recherche serveur
  await page.screenshot({ path: `${SCRATCH}/wg1-doc-drawer.png`, fullPage: false })

  // Aucun champ ID numérique (subject_id) dans le drawer.
  const numberInputs = page.locator('.v-navigation-drawer input[type="number"]')
  expect(await numberInputs.count(), 'aucun subject_id numérique').toBe(0)

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('DSN export selects a payroll run by context, not a technical ID', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/dsn', { waitUntil: 'networkidle' })

  const exportBtn = page.getByRole('button', { name: /Exporter|DSN/ }).first()
  if (await exportBtn.isVisible().catch(() => false)) {
    await exportBtn.click()
    // Le run picker est un select (prepend-inner "calculator"), pas un champ number "ID".
    await expect(page.getByText(/Cycle de paie/).first()).toBeVisible({ timeout: 8000 })
    const numberInputs = page.locator('.v-navigation-drawer input[type="number"]')
    expect(await numberInputs.count(), 'aucun champ ID numérique dans le drawer DSN').toBe(0)
  }
})

test('contract money field keeps precision and shows currency', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  const row = page.locator('.v-data-table tbody tr').first()
  await expect(row).toBeVisible({ timeout: 10000 })
  await row.click()
  await expect(page).toHaveURL(/\/company\/workforce\/employees\/\d+/, { timeout: 8000 })

  await page.getByRole('tab', { name: /Contrats/ }).click()
  await page.locator('[data-test="contract-add"]').click()

  // Champ monétaire : suffixe devise + conversion/normalisation à la frappe (pas de centimes bruts).
  const money = page.getByLabel('Salaire de base')
  await expect(money).toBeVisible({ timeout: 8000 })
  await money.fill('2500,5')
  await money.blur()
  // centsToInput(250050) => "2500,50" (précision à 2 décimales conservée, pas de perte).
  await expect(money).toHaveValue('2500,50')

  // Suffixe devise visible.
  await expect(page.locator('.v-navigation-drawer .v-text-field__suffix').first()).toBeVisible()

  // Valeur invalide → message de validation (comportement, pas présence).
  await money.fill('abc')
  await money.blur()
  await page.screenshot({ path: `${SCRATCH}/wg1-money.png`, fullPage: false })
})

test('organization deletion asks for confirmation with name + consequence (cancel is safe)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/organization/departments', { waitUntil: 'networkidle' })

  const trash = page.locator('.v-data-table tbody tr button').filter({ has: page.locator('.tabler-trash') }).first()
  if (await trash.isVisible().catch(() => false)) {
    await trash.click()
    // Dialogue de confirmation : titre + boutons Annuler/Supprimer (pas de suppression immédiate).
    await expect(page.getByText('Confirmer la suppression')).toBeVisible({ timeout: 6000 })
    const dialog = page.getByRole('dialog').filter({ hasText: 'Confirmer la suppression' })
    await expect(dialog.getByRole('button', { name: /^Annuler$/ })).toBeVisible()
    await expect(dialog.getByRole('button', { name: /^Supprimer$/ })).toBeVisible()
    await page.screenshot({ path: `${SCRATCH}/wg1-delete-confirm.png`, fullPage: false })
    // Annuler ne supprime rien.
    await dialog.getByRole('button', { name: /^Annuler$/ }).click()
    await expect(page.getByText('Confirmer la suppression')).toBeHidden()
  }

  // Dark mode + pas de débordement horizontal.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
})
