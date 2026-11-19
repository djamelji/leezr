// @ts-check
// ADR-644 (W-G4A) — planning visuel : vues semaine/jour, navigation, filtres, distinction
// brouillon/publié, absences + chevauchements, détail lecture seule, responsive. Navigateur réel.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

// ADR-831 (D-21 bis) — since M6.D (ADR-735) the planning has ONE sub-navigation (Semaine / Jour / Postes / Mois) and a
// SHARED date navigator ; the default mode is the Semaine grid. The W-G4A FullCalendar timeline is the « Jour »
// mode (`plan-view-calendar`), controlled, with its own toolbar hidden ; it keeps its filters and legend.
const shot = name => test.info().outputPath(`${name}.png`)

async function openPlanningCalendar(page) {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-calendar"]').click()
  await expect(page.locator('.fc')).toBeVisible({ timeout: 12000 })
}

test('planning calendar renders week view, switches to day, navigates', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await openPlanningCalendar(page)

  // Jour = timeline horaire (timegrid) ; navigation par le navigateur de date PARTAGÉ ; bascule Semaine → Jour.
  await expect(page.locator('.fc-timegrid')).toBeVisible({ timeout: 8000 })
  const before = await page.locator('.fc-col-header-cell').first().getAttribute('data-date')
  await page.getByRole('button', { name: 'Précédent' }).first().click()
  await page.waitForTimeout(400)
  expect(await page.locator('.fc-col-header-cell').first().getAttribute('data-date'), 'shared navigator moves the day').not.toBe(before)
  await page.getByRole('button', { name: "Aujourd'hui" }).first().click()
  await page.waitForTimeout(400)
  expect(await page.locator('.fc-col-header-cell').first().getAttribute('data-date')).toBe(before)
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.locator('[data-test="plan-view-calendar"]').click()
  await expect(page.locator('.fc-timegrid')).toBeVisible({ timeout: 8000 })

  // Légende brouillon/publié/absence/chevauchement.
  await expect(page.getByText(/Publié|Published/).first()).toBeVisible()
  await expect(page.getByText(/Chevauchement|Overlap/).first()).toBeVisible()

  await page.screenshot({ path: shot('wg4a-planning'), fullPage: false })
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
    await page.screenshot({ path: shot('wg4a-shift-detail'), fullPage: false })
    await dialog.getByRole('button', { name: /Fermer|Close/ }).click()
  }
})

test('existing grid tab (create/publish) still available — not degraded', async ({ page }) => {
  await openPlanningCalendar(page)
  // La grille existante reste accessible (mode Semaine) avec ses actions de création et de publication.
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(500)
  await expect(page.locator('[data-test="shift-add"]')).toBeVisible()
  await expect(page.locator('[data-test="publish-week"]')).toBeVisible()
})

test('planning is responsive (390px) and dark-mode clean', async ({ page }) => {
  await openPlanningCalendar(page)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(400)
  const overflowMobile = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowMobile, 'no horizontal overflow on mobile').toBeFalsy()
  await page.screenshot({ path: shot('wg4a-planning-mobile'), fullPage: false })

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
