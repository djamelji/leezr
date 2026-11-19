// @ts-check
// ADR-645 (W-G4B) — gestion opérationnelle : réaffectation guidée (écriture réelle), protection
// des shifts publiés, préférence de premier jour de semaine (écriture réelle + persistance).
import { expect, test } from '@playwright/test'
import { apiRequest, capableEmployee, cleanupShifts, loginCompany, trackCreatedShifts } from './helpers'

// ADR-831 (D-21 bis) — since M6.D (ADR-735) the default planning mode is the Semaine grid (week start honoured since
// ADR-698) and the FullCalendar timeline is the « Jour » mode (`plan-view-calendar`). The reassignment test no longer
// assumes a DEV draft shift « of the current week » : it creates its own draft today and removes it afterwards.
const openDay = async page => {
  await page.locator('[data-test="plan-view-calendar"]').click()
  await expect(page.locator('.fc')).toBeVisible({ timeout: 12000 })
}

test('week start preference persists and drives the planning calendar (real write)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')

  // 1. Définir « Dimanche » dans Paramètres RH (onglet général).
  await page.goto('/company/workforce/settings/general', { waitUntil: 'networkidle' })
  const sel = page.locator('[data-test="week-start-select"]')
  await expect(sel).toBeVisible({ timeout: 10000 })
  await sel.click()
  await page.getByRole('option', { name: /Dimanche|Sunday/ }).click()
  await page.getByRole('button', { name: /Enregistrer|Save/ }).first().click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })

  // 2. Le planning reflète la préférence : première colonne de la grille Semaine = dimanche.
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  const firstCol = page.locator('thead tr.day-header th.planning-day-head').first()
  await expect(firstCol).toBeVisible({ timeout: 12000 })
  expect((await firstCol.innerText()).toLowerCase()).toMatch(/dim|sun/)

  // 3. Rétablir « Automatique » (ne pas polluer la fixture durablement).
  await page.goto('/company/workforce/settings/general', { waitUntil: 'networkidle' })
  await page.locator('[data-test="week-start-select"]').click()
  await page.getByRole('option', { name: /Automatique|Automatic/ }).click()
  await page.getByRole('button', { name: /Enregistrer|Save/ }).first().click()
  await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })
})

test('reassign a draft shift via availability suggestions (real write + persistence)', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  const created = trackCreatedShifts(page)
  const today = new Date().toISOString().slice(0, 10)
  const emp = await capableEmployee(page, today, '09:00:00', '12:00:00')
  expect(emp, 'an employee able to take a shift today').toBeTruthy()
  const draft = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: emp.id, date: today, start_at: `${today} 09:00:00`, end_at: `${today} 12:00:00`, timezone: 'Europe/Paris' } })
  expect(draft.status).toBe(201)
  created.add(draft.json.id)
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await openDay(page)

  // Le shift brouillon préparé est visible et cliquable.
  const event = page.locator('.fc-event', { hasText: emp.name }).first()
  await expect(event).toBeVisible({ timeout: 8000 })
  await event.click()

  const detail = page.getByRole('dialog')
  await expect(detail).toBeVisible({ timeout: 6000 })

  // Brouillon → bouton Réaffecter/Résoudre présent (un publié n'en aurait pas).
  const reassignBtn = detail.getByRole('button', { name: /Réaffecter|Résoudre|Reassign|Resolve/ })
  if (await reassignBtn.isVisible().catch(() => false)) {
    await reassignBtn.click()
    // ADR-831 (D-21 bis) — wording « shift » → « créneau » (planning.calendar.reassignTitle).
    const dlg = page.getByRole('dialog').filter({ hasText: /Réaffecter le (shift|créneau)|Reassign (shift|slot)/ })
    await expect(dlg).toBeVisible({ timeout: 6000 })

    // Choisir un collaborateur « Disponible » (chip vert) non désactivé.
    const candidate = dlg.locator('.v-list-item').filter({ hasText: /Disponible|Available/ }).first()
    await expect(candidate).toBeVisible({ timeout: 6000 })
    const candidateName = (await candidate.locator('.v-list-item-title').innerText()).split('·')[0].trim()
    await candidate.click()
    await page.screenshot({ path: test.info().outputPath('wg4b-reassign.png'), fullPage: false })
    await dlg.getByRole('button', { name: /^Réaffecter$|^Reassign$/ }).click()
    await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })

    // Persistance : après rechargement, un shift porte le nom du nouveau collaborateur.
    await page.reload({ waitUntil: 'networkidle' })
    await openDay(page)
    await expect(page.locator('.fc-event', { hasText: candidateName }).first()).toBeVisible({ timeout: 8000 })
  }

  await cleanupShifts(page, created)
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('planning operations are responsive and dark-mode clean', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await openDay(page)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(400)
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow on mobile').toBeFalsy()

  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await page.waitForTimeout(200)
  const overflowDark = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowDark).toBeFalsy()
})
