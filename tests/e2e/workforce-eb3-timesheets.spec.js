// E3-B / B3 — recette feuilles de temps & validations : vue de contrôle (KPI décisionnels), détail
// (totaux distincts planifié/constaté, grille par jour, historique), et transitions RÉELLEMENT persistées.
// Données : WorkforceCockpitDemoSeeder + WorkforceTimesheetDemoSeeder (équipe B2DEMO, leezr-logistics).
//   php artisan db:seed --class=Database\\Seeders\\WorkforceCockpitDemoSeeder
//   php artisan db:seed --class=Database\\Seeders\\WorkforceTimesheetDemoSeeder
//   npx playwright test tests/e2e/workforce-eb3-timesheets.spec.js
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

const openFirstRow = async page => {
  await page.locator('table tbody tr td').first().click()
  await page.waitForURL(/\/company\/workforce\/time\/\d+/, { timeout: 15000 })
  await page.waitForLoadState('networkidle')
}

test('feuilles : contrôle, détail et transitions persistées', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1366, height: 900 })

  const gotoList = async () => {
    await page.goto('/company/workforce/time?tab=timesheets', { waitUntil: 'networkidle' })
    await page.waitForTimeout(800)
  }

  // ── Vue de contrôle : KPI décisionnels présents ──
  await gotoList()
  await expect(page.locator('[data-test="ts-kpi-submitted"]')).toBeVisible({ timeout: 20000 })

  // ── Détail : totaux distincts + historique ──
  await page.locator('[data-test="ts-kpi-draft"]').click()
  await page.waitForTimeout(600)
  await openFirstRow(page)
  await expect(page.getByText('Planifié', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('Constaté', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('Historique', { exact: true }).first()).toBeVisible()

  // ── Transition SOUMISSION réellement persistée (brouillon → soumise) ──
  const submit = page.locator('[data-test="ts-submit"]')
  await expect(submit).toBeVisible()
  const [subResp] = await Promise.all([
    page.waitForResponse(r => /\/timesheets\/\d+\/submit$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    submit.click(),
  ])
  expect(subResp.status()).toBe(200)
  // L'état persisté se reflète : le bouton Soumettre disparaît, Approuver apparaît.
  await expect(page.locator('[data-test="ts-approve"]')).toBeVisible({ timeout: 10000 })

  // ── Transition APPROBATION réellement persistée (soumise → approuvée) ──
  const [appResp] = await Promise.all([
    page.waitForResponse(r => /\/timesheets\/\d+\/approve$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    (async () => {
      await page.locator('[data-test="ts-approve"]').click()
      await page.locator('[data-test="ts-approve-note"] textarea').first().fill('Heures vérifiées — recette B3.')
      await page.locator('[data-test="ts-approve-confirm"]').click()
    })(),
  ])
  expect(appResp.status()).toBe(200)
  await expect(page.locator('[data-test="ts-lock"]')).toBeVisible({ timeout: 10000 })
})
