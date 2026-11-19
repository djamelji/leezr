// @ts-check
// W-HR-UX-1 — Preuve UI "après" : la fiche collaborateur affiche la CHECKLIST d'intégration guidée.
// ADR-831 (D-21 bis) — la spec dépendait de « Bob Dupont » tel qu'il existait sur le DEV (contrat, intégration
// incomplète) et de l'ancienne liste /company/workforce/employees (absorbée par Équipe, ADR-802). Elle prépare
// désormais SON collaborateur fictif (registre d'exécution) : fiche + contrat, intégration incomplète.
import { test, expect } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers'
import { createRun } from './run-ledger.js'

const run = createRun('hrux-after')

test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })

test('after: employee fiche shows onboarding checklist', async ({ page }, testInfo) => {
  test.setTimeout(90_000)
  await loginCompany(page, 'owner@leezr.test')
  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)

  // Collaborateur fictif avec contrat, intégration incomplète (pas de signature, pas d'accès).
  const created = await apiRequest(page, { method: 'POST', path: '/api/workforce/employees', body: { first_name: 'Bob', last_name: run.name('Dupont'), hire_date: new Date().toISOString().slice(0, 10) } })
  expect(created.status).toBe(201)
  run.track('employee', created.json.id)
  const contract = await apiRequest(page, { method: 'POST', path: `/api/workforce/employees/${created.json.id}/contracts`, body: { contract_type: 'cdi', work_model_key: 'horaire', start_date: new Date().toISOString().slice(0, 10), weekly_hours: 35 } })
  expect(contract.status).toBe(201)

  // Ouvrir la fiche depuis Équipe.
  await page.goto('/company/people', { waitUntil: 'domcontentloaded' })
  await page.getByPlaceholder('Rechercher un collaborateur…').fill(run.name('Dupont'))
  await page.locator('tr', { hasText: run.name('Dupont') }).first().click()
  await expect(page).toHaveURL(new RegExp(`/company/people/employee-${created.json.id}`), { timeout: 15000 })
  await page.waitForTimeout(2000)
  await page.screenshot({ path: testInfo.outputPath('hrux-after-fiche.png'), fullPage: true })

  // La checklist d'intégration est présente et guide la RH.
  const checklist = page.locator('[data-test="person-onboarding"]')
  await expect(checklist.getByText(/Intégration du collaborateur/i)).toBeVisible({ timeout: 10_000 })
  await expect(checklist.getByText(/Contrat de travail/i).first()).toBeVisible()
  const steps = await checklist.locator('text=/Informations du collaborateur|Contrat de travail|Signature du contrat|Accès à l.espace collaborateur/i').count()
  console.log('CHECKLIST_STEPS_VISIBLE=' + steps)
  expect(steps).toBeGreaterThanOrEqual(3)
})
