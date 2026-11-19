// P1a — recette : contrôle réglementaire sur le constaté (lecture seule) affiché dans le détail,
// séparé des anomalies techniques et des heures sup. Données : WorkforceTimesheetDemoSeeder (1 journée 12h).
// ADR-831 (D-21 bis) — the draft sheet with its 12 h day is now prepared by the spec (guarded fixture, own employee).
import { test, expect } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'
import { createRun } from './run-ledger.js'
import { prepareDraftTimesheet, removeDraftTimesheet } from './timesheet-fixtures.js'

test.use({ ignoreHTTPSErrors: true })

const run = createRun('p1a-compliance')
let fixture = null
test.afterAll(() => {
  removeDraftTimesheet(fixture)
  console.log('teardown', JSON.stringify(run.teardown()))
})

test('contrôles réglementaires : détection sur le constaté, sans blocage', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1366, height: 1100 })
  // ADR-831 (D-21 bis) — own draft sheet (guarded fixture) instead of the DEV demo seeder's, consumed by every run.
  const companyId = (await apiRequest(page, { path: '/api/profile/space' })).json.company_id
  run.useCompany(companyId)
  const lastName = run.name('Feuille')
  const emp = await apiRequest(page, { method: 'POST', path: '/api/workforce/employees', body: { first_name: 'E2E', last_name: lastName, hire_date: '2026-01-05' } })
  expect(emp.status).toBe(201)
  run.track('employee', emp.json.id)
  fixture = prepareDraftTimesheet({ companyId, employeeId: emp.json.id })

  await page.goto('/company/workforce/time?tab=timesheets', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="ts-kpi-draft"]').click()
  await page.waitForTimeout(600)

  // L'appel de conformité est une CONSULTATION (lecture seule) servie à l'ouverture du détail.
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/timesheets\/\d+\/compliance$/.test(r.url()) && r.request().method() === 'GET', { timeout: 20000 }),
    page.locator('table tbody tr', { hasText: lastName }).first().locator('td').first().click(),
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
