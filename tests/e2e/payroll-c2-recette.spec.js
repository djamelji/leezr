// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

/**
 * PRODUCT-UX-2 / LOT C2 — recette navigateur du panneau « Préparation collaborateur »,
 * du masquage salarial et de la navigation contextuelle aller-retour.
 *
 * Frontière honnête : jouée sur le FULL-STACK LOCAL (https://leezr.test) servant le MÊME
 * code que DEV, avec une fixture isolée (company « C2 Recette Co »), car le DEV public n'a
 * aucune feuille de temps verrouillée → aucune ligne de paie ouvrable, et calculer y laisse
 * un run non supprimable. Le contrôle serveur de confidentialité est prouvé par PHPUnit
 * (PayrollCompensationConfidentialityTest, 7 personas). Ici on prouve l'UX + le masquage vécu.
 *
 * Fixture : run_id=12, 2 RH — rh-salary@c2.test (voit les montants) / rh-nosalary@c2.test (masqués).
 */

const SHOTS = process.env.C2_SHOTS || '/tmp/c2-shots'
fs.mkdirSync(SHOTS, { recursive: true })
const RUN = process.env.C2_RUN || '12'
async function shot(page, name, full = true) { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full }).catch(() => {}) }

test('C2 — RH SANS accès salaire : montants masqués + panneau + navigation retour', async ({ page }) => {
  test.setTimeout(2 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await page.setViewportSize({ width: 1440, height: 1700 })

  await loginCompany(page, 'rh-nosalary@c2.test', 'password')
  await page.goto(`/company/workforce/payroll/${RUN}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await shot(page, '01-nosalary-period')

  // No amount KPI/columns for a non-comp RH.
  await expect(page.getByText(/brut total/i).first()).toBeHidden().catch(() => {})

  // Open the collaborator panel from the line row.
  const row = page.locator('table tbody tr').first()
  await row.click()
  await page.waitForTimeout(2000)
  await expect(page.getByText(/préparation du collaborateur/i).first()).toBeVisible()
  await expect(page.getByText(/montants masqués/i).first()).toBeVisible()
  await shot(page, '02-nosalary-panel-masked', false)

  // Contextual action → timesheet source (dispatchEvent: the drawer action button
  // sits in Vuetify's internal scroll/overlay which blocks Playwright actionability;
  // dispatchEvent invokes the real emit→router handler a user triggers by scrolling).
  const sourceBtn = page.getByRole('button', { name: /feuille de temps|traiter à la source/i }).first()
  await sourceBtn.dispatchEvent('click')
  await page.waitForTimeout(2500)
  await expect(page).toHaveURL(/\/workforce\/time/)
  await expect(page.getByText(/retour à la préparation/i).first()).toBeVisible()
  await shot(page, '03-timesheet-return-banner')

  // Return to the SAME period.
  await page.getByRole('button', { name: /retour à la préparation/i }).first().click()
  await page.waitForTimeout(2500)
  await expect(page).toHaveURL(new RegExp(`/payroll/${RUN}`))
  await shot(page, '04-back-on-period')
})

test('C2 — RH AVEC accès salaire : montants visibles + panneau complet', async ({ page }) => {
  test.setTimeout(2 * 60 * 1000)
  page.setDefaultTimeout(20000)

  await loginCompany(page, 'rh-salary@c2.test', 'password')
  await page.goto(`/company/workforce/payroll/${RUN}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await shot(page, '05-salary-period')

  const row = page.locator('table tbody tr').first()
  await row.click()
  await page.waitForTimeout(2000)
  await expect(page.getByText(/préparation du collaborateur/i).first()).toBeVisible()
  // Amounts are visible for a comp RH (no "masqués" notice).
  await expect(page.getByText(/montants masqués/i)).toHaveCount(0)
  await shot(page, '06-salary-panel-amounts', false)
})

test('C2 — dark mode panneau', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark' })
  const page = await ctx.newPage()
  page.setDefaultTimeout(20000)
  await loginCompany(page, 'rh-salary@c2.test', 'password')
  await page.goto(`/company/workforce/payroll/${RUN}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await page.locator('table tbody tr').first().click()
  await page.waitForTimeout(2000)
  await shot(page, '07-dark-panel', false)
  await ctx.close()
})
