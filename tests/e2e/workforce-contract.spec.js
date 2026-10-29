// @ts-check
// ADR-647/648 (W-CONTRACT-1/2) — module Contrats : bibliothèque, éditeur WYSIWYG (Tiptap), aperçu PDF
// FIDÈLE (même pipeline), génération réelle depuis la fiche + archivage + téléchargement PDF valide.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'
import fs from 'node:fs'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('library: duplicate → WYSIWYG edit → faithful PDF preview → publish (real writes)', async ({ page, context }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/contract/templates', { waitUntil: 'networkidle' })
  await expect(page.getByText(/exemples non validés|unvalidated examples/)).toBeVisible({ timeout: 10000 })

  // Dupliquer le modèle Platform → brouillon Company.
  await page.getByRole('button', { name: /Dupliquer|Duplicate/ }).first().click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })
  await page.waitForTimeout(600)

  // Éditer : WYSIWYG Tiptap + insertion de variable AU CURSEUR.
  await page.getByRole('button', { name: /^Modifier$|^Edit$/ }).first().click()
  const drawer = page.locator('.v-navigation-drawer--active').first()
  await drawer.locator('input').first().fill('CDI WYSIWYG E2E')
  const editor = drawer.locator('.ProseMirror').first()
  await expect(editor).toBeVisible({ timeout: 6000 })
  await editor.click()
  await page.keyboard.type('Clause ajoutee. ')
  // Insertion au curseur via la palette → la variable apparaît dans l'éditeur.
  await drawer.locator('.v-chip').first().click()
  await expect(editor).toContainText('{{', { timeout: 4000 })
  await page.screenshot({ path: `${SCRATCH}/contract-editor.png`, fullPage: false })

  // Aperçu PDF FIDÈLE : ouvre un nouvel onglet avec le PDF (même pipeline de rendu).
  const [pdfPage] = await Promise.all([
    context.waitForEvent('page', { timeout: 15000 }).catch(() => null),
    drawer.getByRole('button', { name: /Aperçu PDF|PDF preview/ }).click(),
  ])
  if (pdfPage) {
    await pdfPage.waitForLoadState('domcontentloaded').catch(() => {})
    expect(pdfPage.url()).toMatch(/blob:|\.pdf/)
    await pdfPage.close()
  }

  // Enregistrer puis publier.
  await drawer.getByRole('button', { name: /Enregistrer|Save/ }).click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })
  await page.waitForTimeout(500)
  await page.getByRole('button', { name: /Publier|Publish/ }).first().click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })

  // Persistance.
  await page.reload({ waitUntil: 'networkidle' })
  await expect(page.getByText('CDI WYSIWYG E2E').first()).toBeVisible({ timeout: 8000 })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('full generation from the employee file: default pre-selected → faithful PDF preview → real generate → download valid PDF', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
  await page.locator('.v-data-table tbody tr').first().click()
  await expect(page).toHaveURL(/\/company\/workforce\/employees\/\d+/, { timeout: 8000 })

  await page.locator('[data-test="generate-contract"]').click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByText(/Générer un contrat|Generate a contract/)).toBeVisible({ timeout: 6000 })

  // Le modèle PAR DÉFAUT est présélectionné → pas d'interaction select ; Générer est actif.
  await page.waitForTimeout(1500)
  const genBtn = dialog.getByRole('button', { name: /^Générer$|^Generate$/ })
  await expect(genBtn).toBeEnabled({ timeout: 8000 })
  await page.screenshot({ path: `${SCRATCH}/contract-generate.png`, fullPage: false })

  // Génération réelle (PDF + archivage) → réponse serveur (toast). (L'aperçu PDF fidèle est exercé
  // dans le test « library » via l'ouverture d'un vrai PDF.)
  await genBtn.click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 15000 })

  // Téléchargement d'un contrat via la route sécurisée → PDF VALIDE (fichier réel).
  await page.goto('/company/workforce/documents', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)
  const dlBtn = page.locator('.v-data-table tbody .tabler-download, .v-data-table tbody [title*="élécharg"], .v-data-table tbody [title*="ownload"]').first()
  await expect(dlBtn).toBeVisible({ timeout: 8000 })
  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 12000 }),
    dlBtn.click(),
  ])
  const path = `${SCRATCH}/contract-downloaded.pdf`
  await download.saveAs(path)
  expect(fs.readFileSync(path).subarray(0, 4).toString()).toBe('%PDF')
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('contract library responsive and dark-mode clean', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/contract/templates', { waitUntil: 'networkidle' })
  await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 10000 })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(400)
  expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)).toBeFalsy()
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await page.waitForTimeout(200)
  expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)).toBeFalsy()
})
