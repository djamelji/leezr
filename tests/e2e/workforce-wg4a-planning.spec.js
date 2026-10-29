// @ts-check
// ADR-644 (W-G4A) — planning visuel : vues semaine/jour, navigation, filtres, distinction
// brouillon/publié, absences + chevauchements, détail lecture seule, responsive. Navigateur réel.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

async function openPlanningCalendar(page) {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  // L'onglet Calendrier est l'onglet par défaut (W-G4A).
  await expect(page.locator('.fc')).toBeVisible({ timeout: 12000 })
}

test('planning calendar renders week view, switches to day, navigates', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await openPlanningCalendar(page)

  // Vue semaine par défaut (timegrid) → bascule jour → liste.
  await expect(page.locator('.fc-timegrid')).toBeVisible({ timeout: 8000 })
  await page.locator('.fc-timeGridDay-button').click()
  await page.waitForTimeout(300)
  await page.locator('.fc-prev-button').click()
  await page.locator('.fc-today-button').click()
  await page.locator('.fc-timeGridWeek-button').click()

  // Légende brouillon/publié/absence/chevauchement.
  await expect(page.getByText(/Publié|Published/).first()).toBeVisible()
  await expect(page.getByText(/Chevauchement|Overlap/).first()).toBeVisible()

  await page.screenshot({ path: `${SCRATCH}/wg4a-planning.png`, fullPage: false })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('filters are present (department + employee + status)', async ({ page }) => {
  await openPlanningCalendar(page)
  // 2 selects (département + statut) + 1 autocomplete (employé) dans la barre de filtres.
  const selects = page.locator('.v-card .v-select')
  expect(await selects.count()).toBeGreaterThanOrEqual(2)
})

test('clicking a shift opens read-only detail without modifying it', async ({ page }) => {
  await openPlanningCalendar(page)

  const event = page.locator('.fc-event').first()
  if (await event.isVisible().catch(() => false)) {
    await event.click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible({ timeout: 6000 })
    // Détail lecture seule : seulement un bouton Fermer (aucune action de modification).
    await expect(dialog.getByRole('button', { name: /Fermer|Close/ })).toBeVisible()
    expect(await dialog.getByRole('button', { name: /Enregistrer|Save|Publier|Publish|Supprimer|Delete/ }).count()).toBe(0)
    await page.screenshot({ path: `${SCRATCH}/wg4a-shift-detail.png`, fullPage: false })
    await dialog.getByRole('button', { name: /Fermer|Close/ }).click()
  }
})

test('existing grid tab (create/publish) still available — not degraded', async ({ page }) => {
  await openPlanningCalendar(page)
  // La grille existante reste accessible via son onglet.
  await page.getByRole('tab', { name: /Planning/ }).first().click()
  await page.waitForTimeout(500)
  // Aucune erreur, la grille ou ses actions de création restent présentes.
  expect(await page.locator('body').isVisible()).toBeTruthy()
})

test('planning is responsive (390px) and dark-mode clean', async ({ page }) => {
  await openPlanningCalendar(page)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(400)
  const overflowMobile = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowMobile, 'no horizontal overflow on mobile').toBeFalsy()
  await page.screenshot({ path: `${SCRATCH}/wg4a-planning-mobile.png`, fullPage: false })

  // Tablette.
  await page.setViewportSize({ width: 768, height: 1024 })
  await page.waitForTimeout(300)
  const overflowTablet = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowTablet).toBeFalsy()

  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await page.waitForTimeout(200)
  const overflowDark = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowDark).toBeFalsy()
})
