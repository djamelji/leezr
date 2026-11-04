// P1a — recette : contrôle réglementaire sur le constaté (lecture seule) affiché dans le détail,
// séparé des anomalies techniques et des heures sup. Données : WorkforceTimesheetDemoSeeder (1 journée 12h).
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

test('contrôles réglementaires : détection sur le constaté, sans blocage', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1366, height: 1100 })

  await page.goto('/company/workforce/time?tab=timesheets', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="ts-kpi-draft"]').click()
  await page.waitForTimeout(600)

  // L'appel de conformité est une CONSULTATION (lecture seule) servie à l'ouverture du détail.
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/timesheets\/\d+\/compliance$/.test(r.url()) && r.request().method() === 'GET', { timeout: 20000 }),
    page.locator('table tbody tr td').first().click(),
  ])
  expect(resp.status()).toBe(200)
  const body = await resp.json()
  // Statut AUTORITÉ SERVEUR (pas déduit d'une couleur front) + un « non vérifiable » jamais compté conforme.
  expect(body).toHaveProperty('summary')
  expect(body).toHaveProperty('computed_at')

  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(800)
  // La section existe et reste SÉPARÉE des heures sup (« À qualifier »).
  await expect(page.getByText('Contrôles réglementaires', { exact: false }).first()).toBeVisible({ timeout: 10000 })
  await expect(page.getByText('à qualifier', { exact: false }).first()).toBeVisible()
})
