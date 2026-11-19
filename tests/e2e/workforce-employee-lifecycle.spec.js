// @ts-check
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany, clickAndExpectApi, createEmployeeViaTeam, openPersonRubric } from './helpers'
import { createRun } from './run-ledger.js'

// ADR-831 (D-21 bis) — the employee file lives in Équipe (/company/people/employee-:id, ADR-802/777) : the contract
// section is reused inside the « Documents & contrats » rubric. The employee is recorded in the run ledger.
const run = createRun('employee-lifecycle')
test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })

// Full HR lifecycle driven by a manager (owner@leezr.test) on the isolated local base:
//   create employee → add contract → schedule a shift in planning.
// Every step is a real API call (POST, status < 400) + real persistence (verified on reload).
// No bypass: real Sanctum SPA, real RBAC-gated pages/buttons, real business validation.
//
// AppDateTimePicker is a flatpickr with allowInput:false (readonly input) — a genuinely
// hard-to-drive Vuetify field. We set it through the widget's own instance (input._flatpickr
// .setDate(d, true)), which is exactly what clicking a calendar day does internally — it
// exercises the real v-model binding and the real submit, bypassing nothing business-side.

/** Set a flatpickr-backed AppDateTimePicker via its own instance (triggers the change event). */
async function setPickerDate(page, testid, isoDate) {
  const input = page.locator(`[data-test="${testid}"] input`).first()
  await input.waitFor({ state: 'attached', timeout: 10000 })
  await input.evaluate((el, d) => {
    // flatpickr attaches itself to the input element as `_flatpickr`.
    el._flatpickr.setDate(d, true)
  }, isoDate)
}

test('manager: create employee → add contract → schedule shift (persisted)', async ({ page }) => {
  const firstName = 'E2E'
  const lastName = run.name('Lifecycle')
  const fullName = `${firstName} ${lastName}`

  await loginCompany(page, 'owner@leezr.test')
  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)

  // ─── Step 1: create the employee ──────────────────────────────────────────
  // ADR-802 : tiroir unique d'Équipe (date d'embauche par défaut : aujourd'hui).
  const created = await createEmployeeViaTeam(page, { firstName, lastName })
  expect(created.status()).toBe(201)
  const employee = await created.json()
  expect(employee.id, 'created employee id').toBeTruthy()
  const employeeId = employee.id
  run.track('employee', employeeId)

  // ─── Step 2: add a contract to that employee ──────────────────────────────
  await page.goto(`/company/people/employee-${employeeId}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await openPersonRubric(page, 'Contrat')
  await page.locator('[data-test="contract-add"]').click()
  await expect(page.getByText('Ajouter un contrat').first()).toBeVisible({ timeout: 10000 })
  // contract_type defaults to 'cdi', work model 'horaire', comp 'monthly' — only start_date is required.
  await setPickerDate(page, 'contract-start-date', '2026-01-06')

  const contract = await clickAndExpectApi(page,
    { urlIncludes: `/workforce/employees/${employeeId}/contracts`, method: 'POST' },
    () => page.locator('[data-test="contract-submit"]').click())
  expect(contract.status(), 'contract created').toBeLessThan(400)

  // ─── Step 2b: activate the contract (ANO-010) ─────────────────────────────
  // A contract is created `draft`; it must be activated before the employee can be
  // scheduled (shift rule I4) or paid. Drives the Activate action exposed in the timeline.
  await page.waitForTimeout(1000)
  const activate = page.locator('[data-test="contract-activate"]').first()
  await expect(activate, 'draft contract shows an Activate action').toBeVisible({ timeout: 10000 })
  await clickAndExpectApi(page,
    { urlIncludes: `/workforce/employees/${employeeId}/contracts/`, method: 'POST' },
    () => activate.click())

  // ─── Step 3: schedule a shift for the employee in planning ────────────────
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1800)
  await page.locator('[data-test="shift-add"]').click()
  await expect(page.getByText('Nouveau créneau').first()).toBeVisible({ timeout: 10000 })

  // Employee autocomplete — open, type, pick the option for our new employee.
  await page.locator('[data-test="shift-employee"]').click()
  await page.locator('[data-test="shift-employee"] input').fill(lastName)
  await page.getByRole('option', { name: fullName }).first().click()

  await setPickerDate(page, 'shift-date', '2026-01-07')
  await page.locator('[data-test="shift-start"] input').fill('09:00')
  await page.locator('[data-test="shift-end"] input').fill('17:00')

  // ADR-831 (D-21 bis) — since Lot 4B1 an assignment beyond the coverage need asks for an explicit confirmation
  // (422 overstaffing_requires_confirmation, no write) ; the cloned data already staffs that day : confirm through
  // the real dialog, then the shift MUST be created.
  const isShiftPost = r => /\/workforce\/planning\/shifts$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST'
  let [shift] = await Promise.all([page.waitForResponse(isShiftPost, { timeout: 20000 }), page.locator('[data-test="shift-submit"]').click()])
  if (shift.status() === 422 && (await shift.json()).code === 'overstaffing_requires_confirmation') {
    const confirm = page.locator('[data-test="overstaffing-confirm"]')
    await expect(confirm).toBeVisible({ timeout: 10000 })
    ;[shift] = await Promise.all([page.waitForResponse(isShiftPost, { timeout: 20000 }), confirm.click()])
  }
  expect(shift.status(), 'shift created').toBe(201)
  const shiftId = (await shift.json()).id
  const listed = (await apiRequest(page, { path: `/api/workforce/planning/shifts?from=2026-01-07&to=2026-01-07&employee_id=${employeeId}&per_page=100` })).json
  const rows = Array.isArray(listed) ? listed : (listed?.data ?? listed?.shifts ?? [])
  expect(rows.some(x => x.id === shiftId), 'shift persisted').toBe(true)
  await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${shiftId}` })

  // ─── Persistence: reload employees list → new employee is recorded ────────
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  // the list is paginated (≈50 people on the cloned data) : look the new employee up through the search box
  await page.locator('[data-test="fp-search"] input').first().fill(lastName)
  await expect(page.getByText(fullName).first()).toBeVisible({ timeout: 10000 })
})
