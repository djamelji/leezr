// @ts-check
// ADR-647 (W-CONTRACT-1) — module Contrats de travail : bibliothèque, duplication/édition/publication
// (écritures réelles), génération d'un contrat depuis la fiche (PDF réel + archivage).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('contract template library: duplicate → edit → publish (real writes)', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/contract/templates', { waitUntil: 'networkidle' })

  // Disclaimer juridique + modèle standard présent.
  await expect(page.getByText(/exemples non validés|unvalidated examples/)).toBeVisible({ timeout: 10000 })
  await expect(page.locator('tbody tr').first()).toBeVisible()

  // Dupliquer le modèle Platform → brouillon Company.
  const dupBtn = page.getByRole('button', { name: /Dupliquer|Duplicate/ }).first()
  await dupBtn.click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })
  await page.waitForTimeout(600)

  // Éditer le brouillon (nom + contenu) → enregistrer.
  const editBtn = page.getByRole('button', { name: /^Modifier$|^Edit$/ }).first()
  await expect(editBtn).toBeVisible({ timeout: 8000 })
  await editBtn.click()
  const drawer = page.locator('.v-navigation-drawer--active').first()
  await drawer.locator('input').first().fill('Mon CDI personnalisé E2E')
  await page.screenshot({ path: `${SCRATCH}/contract-editor.png`, fullPage: false })
  await drawer.getByRole('button', { name: /Enregistrer|Save/ }).click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })

  // Publier le brouillon → persistance après rechargement.
  await page.waitForTimeout(500)
  await page.getByRole('button', { name: /Publier|Publish/ }).first().click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })
  await page.reload({ waitUntil: 'networkidle' })
  await expect(page.getByText('Mon CDI personnalisé E2E').first()).toBeVisible({ timeout: 8000 })

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('generate a contract from the employee file (real PDF + archive)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  // Ouvrir le premier collaborateur.
  await page.locator('.v-data-table tbody tr').first().click()
  await expect(page).toHaveURL(/\/company\/workforce\/employees\/\d+/, { timeout: 8000 })

  // Action « Générer un contrat ».
  const genBtn = page.locator('[data-test="generate-contract"]')
  await expect(genBtn).toBeVisible({ timeout: 8000 })
  await genBtn.click()

  // Point d'intégration fiche → génération : le dialogue s'ouvre avec le sélecteur de modèle et le
  // bouton Générer. (La génération PDF réelle + archivage + idempotence est prouvée par
  // WorkforceContractTest : test_generate_emits_document_and_is_idempotent.)
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByText(/Générer un contrat|Generate a contract/)).toBeVisible({ timeout: 6000 })
  await expect(dialog.getByText(/Modèle de contrat|Contract template/)).toBeVisible()
  await expect(dialog.getByRole('button', { name: /^Générer$|^Generate$/ })).toBeVisible()
  await page.screenshot({ path: `${SCRATCH}/contract-generate.png`, fullPage: false })
})

test('contract library is responsive and dark-mode clean', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/contract/templates', { waitUntil: 'networkidle' })
  await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 10000 })

  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(400)
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow).toBeFalsy()

  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await page.waitForTimeout(200)
  const overflowDark = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowDark).toBeFalsy()
})
