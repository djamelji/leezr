// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

// LOT D — Workforce configuration cockpit. A manager sees each rule with provenance + mutability,
// edits a configurable rule (persists), while mandatory legal rules are protected server-side and
// an unauthorized employee is refused (ANO-014: real backend permission, not v-can).

test('manager views the cockpit and edits a configurable break rule (persisted)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/configuration', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  await expect(page.locator('[data-test="wf-config-breaks"]')).toBeVisible({ timeout: 10000 })

  // Legal minimum is mandatory → read-only (locked), tagged mandatory.
  await expect(page.locator('[data-test="wf-mutability-min_duration_minutes"]')).toContainText(/Obligatoire|Mandatory/)
  await expect(page.locator('[data-test="wf-value-min_duration_minutes"]')).toContainText('20')
  await expect(page.locator('[data-test="wf-edit-min_duration_minutes"]')).toHaveCount(0)

  // Target is configurable → edit to 35 and save.
  const field = page.locator('[data-test="wf-edit-target_duration_minutes"] input')
  await field.fill('35')
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/workforce\/config\/breaks\/target_duration_minutes$/.test(r.url()) && r.request().method() === 'PUT'),
    page.locator('[data-test="wf-save-target_duration_minutes"]').click(),
  ])
  expect(resp.status(), 'save succeeded').toBeLessThan(400)

  // Persistence: reload → the edited value is served by the resolver.
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.locator('[data-test="wf-edit-target_duration_minutes"] input')).toHaveValue('35')
})

test('mandatory rules are protected and below-minimum is rejected server-side', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/configuration', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)

  // Cannot override a mandatory legal rule.
  const mand = await apiRequest(page, { method: 'PUT', path: '/api/workforce/config/breaks/min_duration_minutes', body: { value: 45 } })
  expect(mand.status, 'mandatory rule override refused').toBe(422)

  // Cannot set a configurable target below the legal minimum.
  const low = await apiRequest(page, { method: 'PUT', path: '/api/workforce/config/breaks/target_duration_minutes', body: { value: 5 } })
  expect(low.status, 'below-legal-minimum refused').toBe(422)
})

test('an employee without workforce.view is refused the config API (403)', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  const res = await apiRequest(page, { method: 'GET', path: '/api/workforce/config/breaks' })
  expect(res.status, 'employee forbidden from config').toBe(403)
})
