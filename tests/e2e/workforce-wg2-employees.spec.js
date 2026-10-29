// @ts-check
// ADR-642 (W-G2) — gestion avancée des collaborateurs : filtres serveur, import guidé (dry-run →
// apply), export, multi-sélection + actions groupées. Comportements vérifiés en navigateur réel.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'
import fs from 'node:fs'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('advanced filters update the list and sync the URL', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  await expect(page.locator('.v-data-table tbody tr').first()).toBeVisible({ timeout: 10000 })

  // Filtre par statut (1er .v-select de la barre de filtres) → l'URL reflète le filtre actif.
  const statusSelect = page.locator('.v-card .v-select').first()
  await statusSelect.click()
  await page.locator('.v-overlay .v-list-item').first().click()
  await page.waitForTimeout(600)
  expect(page.url()).toMatch(/status=/)

  await page.screenshot({ path: `${SCRATCH}/wg2-filters.png`, fullPage: false })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('guided import: file → mapping → preview (dry-run) → confirm → created', async ({ page }) => {
  const stamp = Date.now()
  const ref = `E2E-${stamp}`
  const csvPath = `${SCRATCH}/wg2-import-${stamp}.csv`

  fs.writeFileSync(csvPath, [
    'first_name,last_name,employee_number,email,hire_date,status',
    `Testor,Import${stamp},${ref},testor${stamp}@example.com,2026-01-15,active`,
  ].join('\n'))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  await page.getByRole('button', { name: /Importer|Import/ }).first().click()
  await expect(page.getByText(/Importer des collaborateurs|Import employees/)).toBeVisible({ timeout: 8000 })

  // 1. Fichier
  await page.locator('.v-navigation-drawer input[type="file"]').setInputFiles(csvPath)
  await page.waitForTimeout(400)
  await page.getByRole('button', { name: /Suivant|Next/ }).click()

  // 2. Mapping auto → prévisualisation
  await page.getByRole('button', { name: /Prévisualiser|Preview/ }).click()
  await expect(page.getByText(/À créer|To create/).first()).toBeVisible({ timeout: 8000 })
  await page.screenshot({ path: `${SCRATCH}/wg2-import-preview.png`, fullPage: false })

  // 3. Confirmer (dry-run ne devait rien créer ; l'écriture a lieu ici)
  await page.getByRole('button', { name: /Importer \d+ collaborateur|Import \d+ employee/ }).click()
  await expect(page.getByText(/créé\(s\)|created/).first()).toBeVisible({ timeout: 10000 })
  await page.getByRole('button', { name: /Fermer|Close/ }).click()

  // 4. Le collaborateur importé est retrouvable par recherche.
  const search = page.locator('.v-card input').first()
  await search.fill(ref)
  await page.waitForTimeout(800)
  await expect(page.locator('.v-data-table tbody').getByText(`Import${stamp}`)).toBeVisible({ timeout: 8000 })
})

test('export triggers a CSV download', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 10000 }),
    page.getByRole('button', { name: /Exporter|Export/ }).first().click(),
  ])
  expect(download.suggestedFilename()).toMatch(/\.csv$/)
})

test('bulk change department: select → confirm → persisted (reserve W-G2 §3)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
  await expect(page.locator('.v-data-table tbody tr').first()).toBeVisible({ timeout: 10000 })

  // Noms des deux premières lignes (pour vérifier la persistance après coup).
  const name0 = (await page.locator('.v-data-table tbody tr').nth(0).locator('td').nth(1).innerText()).trim()
  const name1 = (await page.locator('.v-data-table tbody tr').nth(1).locator('td').nth(1).innerText()).trim()

  const checkboxes = page.locator('.v-data-table tbody tr .v-selection-control input[type="checkbox"]')
  await checkboxes.nth(0).check()
  await checkboxes.nth(1).check()

  await page.getByRole('button', { name: /Changer de département|Change department/ }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible({ timeout: 6000 })

  // Choisir le premier département proposé.
  await dialog.locator('.v-select').first().click()
  const option = page.locator('.v-overlay .v-list-item').first()
  const deptName = (await option.innerText()).trim()
  await option.click()

  // Prévisualisation d'éligibilité puis application.
  await expect(dialog.getByText(/éligible|eligible/)).toBeVisible({ timeout: 6000 })
  await dialog.getByRole('button', { name: /Appliquer|Apply/ }).click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })

  // Persistance : filtrer par ce département → les 2 collaborateurs y figurent.
  const deptFilter = page.locator('.v-card .v-select').nth(1) // 2e select = département (après statut)
  await deptFilter.click()
  await page.getByRole('option', { name: deptName }).first().click()
  await page.waitForTimeout(800)
  const bodyText = await page.locator('.v-data-table tbody').innerText()
  expect(bodyText).toContain(name0.split('\n')[0])
  expect(bodyText).toContain(name1.split('\n')[0])
})

test('multi-select reveals the bulk action bar with exact count', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  await expect(page.locator('.v-data-table tbody tr').first()).toBeVisible({ timeout: 10000 })

  // Sélectionne les deux premières lignes via leurs cases.
  const checkboxes = page.locator('.v-data-table tbody tr .v-selection-control input[type="checkbox"]')
  await checkboxes.nth(0).check()
  await checkboxes.nth(1).check()

  // La barre d'actions groupées affiche le compte exact.
  await expect(page.getByText(/2 sélectionné|2 selected/)).toBeVisible({ timeout: 6000 })
  await expect(page.getByRole('button', { name: /Changer de département|Change department/ })).toBeVisible()
  await page.screenshot({ path: `${SCRATCH}/wg2-bulk-bar.png`, fullPage: false })

  // Dark mode + pas de débordement.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
})
