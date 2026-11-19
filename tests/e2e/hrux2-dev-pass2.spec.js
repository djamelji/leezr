// @ts-check
// W-HR-UX-2 — création d'un collaborateur fictif via l'UI → redirection fiche → checklist d'intégration.
// ADR-831 (D-21 bis) — la spec visait le DEV réel (dev.leezr.com) et l'ancien tiroir « Ajouter un employé » de
// /company/workforce/employees, remplacé par le tiroir unique d'Équipe (ADR-802). Elle s'exécute sur le clone
// marqué, avec registre d'exécution. Mêmes vérifications : POST réel, fiche ouverte, checklist visible.
import { test, expect } from '@playwright/test'
import { apiRequest, createEmployeeViaTeam, loginCompany } from './helpers'
import { createRun } from './run-ledger.js'

const run = createRun('hrux2')

test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })

test('DEV pass2: create fictitious employee via UI → fiche + checklist', async ({ page }, testInfo) => {
  test.setTimeout(120000)
  await loginCompany(page, 'owner@leezr.test')
  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)

  const resp = await createEmployeeViaTeam(page, { firstName: 'Thomas', lastName: run.name('Bernard'), email: run.email('thomas') })
  console.log('DEV_CREATE_STATUS=' + resp.status())
  expect(resp.status()).toBe(201)
  const id = (await resp.json()).id
  run.track('employee', id)
  await page.waitForTimeout(2000)
  console.log('DEV_URL_AFTER=' + page.url())
  await expect(page).toHaveURL(new RegExp(`/company/people/employee-${id}`))
  await expect(page.locator('[data-test="person-onboarding"]').getByText(/Intégration du collaborateur/i)).toBeVisible({ timeout: 12000 })
  await page.screenshot({ path: testInfo.outputPath('hrux2-fiche-checklist.png'), fullPage: true })
})
