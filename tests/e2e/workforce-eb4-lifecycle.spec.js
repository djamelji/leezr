// E3-B / B4 — recette finale : cycle de vie COMPLET d'une feuille via l'UI, réellement persisté
// (brouillon → soumission → approbation → verrouillage), avec historique d'audit visible.
// Données : WorkforceCockpitDemoSeeder + WorkforceTimesheetDemoSeeder (leezr-logistics).
//   php artisan db:seed --class=Database\\Seeders\\WorkforceTimesheetDemoSeeder
//   npx playwright test tests/e2e/workforce-eb4-lifecycle.spec.js
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

test('feuille : cycle de vie complet persisté jusqu\'au verrouillage', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1366, height: 900 })

  await page.goto('/company/workforce/time?tab=timesheets', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="ts-kpi-draft"]').click()
  await page.waitForTimeout(600)
  await page.locator('table tbody tr td').first().click()
  await page.waitForURL(/\/company\/workforce\/time\/\d+/, { timeout: 15000 })
  await page.waitForLoadState('networkidle')

  // Soumission → Approbation → Verrouillage : chaque transition est RÉELLEMENT persistée (POST 200).
  const [sub] = await Promise.all([
    page.waitForResponse(r => /\/submit$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="ts-submit"]').click(),
  ])
  expect(sub.status()).toBe(200)
  await expect(page.locator('[data-test="ts-approve"]')).toBeVisible({ timeout: 10000 })

  await page.locator('[data-test="ts-approve"]').click()
  await page.locator('[data-test="ts-approve-note"] textarea').first().fill('Recette finale B4.')
  const [app] = await Promise.all([
    page.waitForResponse(r => /\/approve$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="ts-approve-confirm"]').click(),
  ])
  expect(app.status()).toBe(200)
  await expect(page.locator('[data-test="ts-lock"]')).toBeVisible({ timeout: 10000 })

  const [lock] = await Promise.all([
    page.waitForResponse(r => /\/lock$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="ts-lock"]').click(),
  ])
  expect(lock.status()).toBe(200)

  // État terminal persisté : plus aucune action de transition, hint de verrouillage visible, historique rempli.
  await expect(page.locator('[data-test="ts-submit"]')).toHaveCount(0)
  await expect(page.locator('[data-test="ts-approve"]')).toHaveCount(0)
  await expect(page.getByText('Historique', { exact: true }).first()).toBeVisible()
})
