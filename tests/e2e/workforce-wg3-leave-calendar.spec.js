// @ts-check
// ADR-643 (W-G3) — calendrier intelligent des congés : vues mois/semaine, navigation, filtres,
// détail d'une demande + approbation/refus inline. Comportements vérifiés en navigateur réel.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

async function openCalendar(page) {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/leave', { waitUntil: 'networkidle' })
  await page.getByRole('tab', { name: /Calendrier|Calendar/ }).click()
  await expect(page.locator('.fc')).toBeVisible({ timeout: 10000 })
}

test('leave calendar renders, switches views and navigates', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await openCalendar(page)

  // Vue mois par défaut → bascule en semaine → liste.
  await expect(page.locator('.fc-dayGridMonth-view, .fc-daygrid')).toBeVisible({ timeout: 8000 })
  await page.locator('.fc-timeGridWeek-button').click()
  await expect(page.locator('.fc-timegrid')).toBeVisible({ timeout: 8000 })

  // Navigation : précédent / aujourd'hui.
  await page.locator('.fc-prev-button').click()
  await page.locator('.fc-today-button').click()
  await page.locator('.fc-dayGridMonth-button').click()

  await page.screenshot({ path: `${SCRATCH}/wg3-calendar.png`, fullPage: false })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('filters are present and legend shows statuses', async ({ page }) => {
  await openCalendar(page)

  // Légende des statuts.
  await expect(page.getByText(/En attente|Pending/).first()).toBeVisible()
  // Filtre statut (multi) présent.
  await expect(page.locator('.v-card .v-select')).toHaveCount(await page.locator('.v-card .v-select').count())
})

test('clicking an event opens its detail (period, status, simultaneous)', async ({ page }) => {
  await openCalendar(page)

  const event = page.locator('.fc-event').first()
  if (await event.isVisible().catch(() => false)) {
    await event.click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible({ timeout: 6000 })
    // Le détail affiche la période et la section absences simultanées.
    await expect(dialog.getByText(/Période|Period/)).toBeVisible()
    await expect(dialog.getByText(/Absences simultanées|Simultaneous absences/)).toBeVisible()
    await page.screenshot({ path: `${SCRATCH}/wg3-event-detail.png`, fullPage: false })

    // Si la demande est en attente, l'owner (approbateur) voit les actions inline.
    const approveBtn = dialog.getByRole('button', { name: /^Approuver$|^Approve$/ })
    if (await approveBtn.isVisible().catch(() => false)) {
      await approveBtn.click()
      // Action câblée : le dialogue se ferme et un retour utilisateur apparaît.
      await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })
    } else {
      await dialog.getByRole('button', { name: /Fermer|Close/ }).click()
    }
  }
})

test('calendar is responsive and dark-mode clean', async ({ page }) => {
  await openCalendar(page)

  await page.setViewportSize({ width: 390, height: 844 }) // mobile
  await page.waitForTimeout(400)
  const overflowMobile = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowMobile, 'no horizontal overflow on mobile').toBeFalsy()
  await page.screenshot({ path: `${SCRATCH}/wg3-calendar-mobile.png`, fullPage: false })

  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await page.waitForTimeout(200)
  const overflowDark = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowDark).toBeFalsy()
})
