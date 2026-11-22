// @ts-check
// ADR-642 (W-G2) — gestion avancée des collaborateurs : filtres serveur, import guidé (dry-run →
// apply), export, multi-sélection + actions groupées. Comportements vérifiés en navigateur réel.
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers'
import { createRun } from './run-ledger.js'
import fs from 'node:fs'

// ADR-831 (D-21 bis) / ADR-864 — /company/workforce/employees redirects to Personnes (/company/people, HR tools included) :
// same list, filters (server-side, URL-synced), guided import, export and bulk actions. Since ADR-817 the
// advanced filters sit behind the « Filtres » toggle (AppFilterPanel) and import/export in the « ⋯ » menu.
// Screenshots go to the test output dir ; the imported employee is recorded in the run ledger.
const run = createRun('wg2-employees')
test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })
// ADR-864 : the list is Personnes (/company/people) ; its filters are always visible (no toggle any more).
const openFilters = async page => { await page.waitForTimeout(200) }
const openMore = async (page, item) => {
  await page.locator('[data-test="emp-more"]').click()
  await page.locator(`[data-test="emp-${item}"]`).click()
}

test('advanced filters update the list and sync the URL', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  await expect(page.locator('.v-data-table tbody tr').first()).toBeVisible({ timeout: 10000 })

  // Filtre par statut (1er .v-select du panneau de filtres) → l'URL reflète le filtre actif.
  await openFilters(page)
  const statusSelect = page.locator('.v-card .v-select').first()
  await statusSelect.click()
  await page.locator('.v-overlay .v-list-item').first().click()
  await page.waitForTimeout(600)
  expect(page.url()).toMatch(/status=/)

  await page.screenshot({ path: test.info().outputPath('wg2-filters.png'), fullPage: false })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('guided import: file → mapping → preview (dry-run) → confirm → created', async ({ page }) => {
  const stamp = run.runId
  const ref = `E2E-${stamp}`
  const csvPath = test.info().outputPath(`wg2-import-${stamp}.csv`)

  fs.writeFileSync(csvPath, [
    'first_name,last_name,employee_number,email,hire_date,status',
    `Testor,${run.name('Import')},${ref},${run.email('testor')},2026-01-15,active`,
  ].join('\n'))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)
  await openMore(page, 'import')
  await expect(page.getByText(/Importer des collaborateurs|Import employees/)).toBeVisible({ timeout: 8000 })

  // 1. Fichier
  await page.locator('.v-navigation-drawer input[type="file"]').setInputFiles(csvPath)
  await page.waitForTimeout(400)
  await page.getByRole('button', { name: /Suivant|Next/ }).click()

  // 2. Mapping auto → prévisualisation
  await page.getByRole('button', { name: /Prévisualiser|Preview/ }).click()
  await expect(page.getByText(/À créer|To create/).first()).toBeVisible({ timeout: 8000 })
  await page.screenshot({ path: test.info().outputPath('wg2-import-preview.png'), fullPage: false })

  // 3. Confirmer (dry-run ne devait rien créer ; l'écriture a lieu ici)
  await page.getByRole('button', { name: /Importer \d+ collaborateur|Import \d+ employee/ }).click()
  await expect(page.getByText(/créé\(s\)|created/).first()).toBeVisible({ timeout: 10000 })
  // ADR-864 : Personnes also has the quick-view « Fermer » — close the IMPORT drawer by its own text button
  await page.locator('.v-navigation-drawer').filter({ hasText: /Importer des collaborateurs|Import employees/ }).locator('button').filter({ hasText: /^\s*(Fermer|Close)\s*$/ }).first().click()

  // 4. Le collaborateur importé est retrouvable par recherche.
  const search = page.locator('[data-test="people-search"] input').first()
  await search.fill(ref)
  await page.waitForTimeout(800)
  await expect(page.locator('.v-data-table tbody').getByText(run.name('Import'))).toBeVisible({ timeout: 8000 })
  const found = await apiRequest(page, { path: `/api/workforce/employees?search=${encodeURIComponent(ref)}` })
  for (const e of found.json?.data || []) run.track('employee', e.id)
})

test('export triggers a CSV download', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 10000 }),
    openMore(page, 'export'),
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
  await openFilters(page)
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
  await page.screenshot({ path: test.info().outputPath('wg2-bulk-bar.png'), fullPage: false })

  // Dark mode + pas de débordement.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
})
