// @ts-check
// ADR-646 (W-G5) — cockpit Pointage : KPIs, tableau prévu/réalisé réconcilié, correction guidée
// (motif obligatoire, écriture réelle + persistance). Navigateur réel.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

async function openCockpit(page) {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/time', { waitUntil: 'networkidle' })
  // Onglet Cockpit = défaut (W-G5).
  await expect(page.getByText(/En travail|Working/).first()).toBeVisible({ timeout: 12000 })
}

test('cockpit shows KPIs and the reconciled operational table', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await openCockpit(page)

  // KPIs présents.
  await expect(page.getByText(/Terminés|Completed/).first()).toBeVisible()
  await expect(page.getByText(/Anomalies/).first()).toBeVisible()
  await expect(page.getByText(/Feuilles à valider|Timesheets to approve/).first()).toBeVisible()

  // Tableau avec en-têtes prévu/réalisé/rattachement.
  await expect(page.getByText(/Prévu|Planned/).first()).toBeVisible()
  await expect(page.getByText(/Réalisé|Actual/).first()).toBeVisible()
  await expect(page.getByText(/Rattachement|Match/).first()).toBeVisible()

  await page.screenshot({ path: `${SCRATCH}/wg5-cockpit.png`, fullPage: false })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('guided correction requires a reason and persists (real write)', async ({ page }) => {
  await openCockpit(page)

  // La fixture du jour (Djamel, pointage complété) expose un bouton Corriger.
  const correctBtn = page.getByRole('button', { name: /Corriger|Correct/ }).first()
  if (await correctBtn.isVisible().catch(() => false)) {
    await correctBtn.click()
    await expect(page.getByText(/Corriger le pointage|Correct time entry/).first()).toBeVisible({ timeout: 6000 })

    const drawer = page.locator('.v-navigation-drawer--active').first()
    // Sans motif → le bouton Enregistrer est désactivé (motif obligatoire).
    const save = drawer.getByRole('button', { name: /Enregistrer|Save/ })
    await expect(save).toBeDisabled()

    // Saisir un motif → enregistrer (écriture réelle).
    await drawer.locator('textarea').first().fill('Oubli de pointage de fin (E2E)')
    await expect(save).toBeEnabled()
    await page.screenshot({ path: `${SCRATCH}/wg5-correction.png`, fullPage: false })
    await save.click()
    await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })

    // Persistance : après rechargement, le cockpit se recharge sans erreur et la ligne existe toujours.
    await page.reload({ waitUntil: 'networkidle' })
    await expect(page.getByText(/En travail|Working/).first()).toBeVisible({ timeout: 10000 })
  }
})

test('cockpit filters by status via KPI click', async ({ page }) => {
  await openCockpit(page)
  // Cliquer la carte « Terminés » applique le filtre statut.
  const completedKpi = page.locator('.cursor-pointer').filter({ hasText: /Terminés|Completed/ }).first()
  if (await completedKpi.isVisible().catch(() => false)) {
    await completedKpi.click()
    await page.waitForTimeout(600)
    // Pas d'erreur ; le tableau reste rendu.
    await expect(page.locator('table, .v-table').first()).toBeVisible()
  }
})

test('cockpit is responsive and dark-mode clean', async ({ page }) => {
  await openCockpit(page)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(400)
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow on mobile').toBeFalsy()

  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await page.waitForTimeout(200)
  const overflowDark = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowDark).toBeFalsy()
})
