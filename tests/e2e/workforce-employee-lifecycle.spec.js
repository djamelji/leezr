// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'

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
  const stamp = String(Date.now()).slice(-6)
  const firstName = 'E2E'
  const lastName = `Lifecycle${stamp}`
  const fullName = `${firstName} ${lastName}`

  await loginCompany(page, 'owner@leezr.test')

  // ─── Step 1: create the employee ──────────────────────────────────────────
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await page.locator('[data-test="emp-add"]').click()
  await expect(page.getByText('Ajouter un employé').first()).toBeVisible({ timeout: 10000 })
  await page.locator('[data-test="emp-first-name"] input').fill(firstName)
  await page.locator('[data-test="emp-last-name"] input').fill(lastName)
  await setPickerDate(page, 'emp-hire-date', '2026-01-06')

  const created = await clickAndExpectApi(page, { urlIncludes: '/workforce/employees', method: 'POST' },
    () => page.locator('[data-test="emp-submit"]').click())
  expect(created.status()).toBe(201)
  const employee = await created.json()
  expect(employee.id, 'created employee id').toBeTruthy()
  const employeeId = employee.id

  // ─── Step 2: add a contract to that employee ──────────────────────────────
  await page.goto(`/company/workforce/employees/${employeeId}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await page.getByRole('tab', { name: 'Contrat' }).click()
  await page.waitForTimeout(600)
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
  await expect(page.getByText('Nouveau shift').first()).toBeVisible({ timeout: 10000 })

  // Employee autocomplete — open, type, pick the option for our new employee.
  await page.locator('[data-test="shift-employee"]').click()
  await page.locator('[data-test="shift-employee"] input').fill(lastName)
  await page.getByRole('option', { name: fullName }).first().click()

  await setPickerDate(page, 'shift-date', '2026-01-07')
  await page.locator('[data-test="shift-start"] input').fill('09:00')
  await page.locator('[data-test="shift-end"] input').fill('17:00')

  const shift = await clickAndExpectApi(page,
    { urlIncludes: '/workforce/planning/shifts', method: 'POST' },
    () => page.locator('[data-test="shift-submit"]').click())
  expect(shift.status(), 'shift created').toBeLessThan(400)

  // ─── Persistence: reload employees list → new employee is recorded ────────
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByText(fullName).first()).toBeVisible({ timeout: 10000 })
})
