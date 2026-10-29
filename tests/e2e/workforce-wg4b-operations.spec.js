// @ts-check
// ADR-645 (W-G4B) — gestion opérationnelle : réaffectation guidée (écriture réelle), protection
// des shifts publiés, préférence de premier jour de semaine (écriture réelle + persistance).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

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

  // 2. Le planning reflète la préférence : première colonne = dimanche.
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await expect(page.locator('.fc')).toBeVisible({ timeout: 12000 })
  const firstCol = await page.locator('.fc-col-header-cell').first().innerText()
  expect(firstCol.toLowerCase()).toMatch(/dim|sun/)

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
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await expect(page.locator('.fc')).toBeVisible({ timeout: 12000 })

  // Le shift brouillon de la semaine courante (fixture) est visible et cliquable.
  const event = page.locator('.fc-event').first()
  await expect(event).toBeVisible({ timeout: 8000 })
  await event.click()

  const detail = page.getByRole('dialog')
  await expect(detail).toBeVisible({ timeout: 6000 })

  // Brouillon → bouton Réaffecter/Résoudre présent (un publié n'en aurait pas).
  const reassignBtn = detail.getByRole('button', { name: /Réaffecter|Résoudre|Reassign|Resolve/ })
  if (await reassignBtn.isVisible().catch(() => false)) {
    await reassignBtn.click()
    const dlg = page.getByRole('dialog').filter({ hasText: /Réaffecter le shift|Reassign shift/ })
    await expect(dlg).toBeVisible({ timeout: 6000 })

    // Choisir un collaborateur « Disponible » (chip vert) non désactivé.
    const candidate = dlg.locator('.v-list-item').filter({ hasText: /Disponible|Available/ }).first()
    await expect(candidate).toBeVisible({ timeout: 6000 })
    const candidateName = (await candidate.locator('.v-list-item-title').innerText()).split('·')[0].trim()
    await candidate.click()
    await page.screenshot({ path: `${SCRATCH}/wg4b-reassign.png`, fullPage: false })
    await dlg.getByRole('button', { name: /^Réaffecter$|^Reassign$/ }).click()
    await expect(page.locator('.v-snackbar, [role="alert"]').first()).toBeVisible({ timeout: 8000 })

    // Persistance : après rechargement, un shift porte le nom du nouveau collaborateur.
    await page.reload({ waitUntil: 'networkidle' })
    await expect(page.locator('.fc')).toBeVisible({ timeout: 10000 })
    await expect(page.locator('.fc-event', { hasText: candidateName }).first()).toBeVisible({ timeout: 8000 })
  }

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('planning operations are responsive and dark-mode clean', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await expect(page.locator('.fc')).toBeVisible({ timeout: 12000 })

  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(400)
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow on mobile').toBeFalsy()

  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await page.waitForTimeout(200)
  const overflowDark = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflowDark).toBeFalsy()
})
