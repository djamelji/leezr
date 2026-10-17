// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'

// Full multi-user leave workflow on the isolated local base:
//   Employee (bob, self-service) requests → Manager (owner) approves → persistence.
//   + unauthorized approval + server-side validation.
// Real Sanctum SPA, real endpoints, real persistence. Identities: bob@leezr.test
// (employee, Driver role) ; owner@leezr.test (manager, workforce.leave_approve).

async function requestLeave(page, from, to, days) {
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await page.locator('[data-test="me-request-leave"]').click()
  await page.waitForTimeout(600)
  await page.locator('[data-test="me-leave-from"] input').fill(from)
  await page.locator('[data-test="me-leave-to"] input').fill(to)
  await page.locator('[data-test="me-leave-days"] input').fill(String(days))
}

// Unique non-overlapping future range per run (overlap is rejected by the use case).
function uniqueRange() {
  const base = new Date(2027, 0, 1 + (Date.now() % 600))
  const from = base.toISOString().slice(0, 10)
  const end = new Date(base.getTime() + 2 * 86400000).toISOString().slice(0, 10)
  return { from, to: end }
}

test('employee requests leave → manager approves → persists (multi-user)', async ({ page }) => {
  const { from, to } = uniqueRange()

  // 1) Employee self-service request → 201 Created (ANO-007 path).
  await loginCompany(page, 'bob@leezr.test')
  await requestLeave(page, from, to, 3)
  const created = await clickAndExpectApi(page, { urlIncludes: '/workforce/me/leaves', method: 'POST' },
    () => page.locator('[data-test="me-leave-submit"]').click())
  expect(created.status()).toBe(201)

  // 2) Manager finds the pending request and approves it via the UI.
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/leave', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  const approveBtn = page.locator('button[title="Approuver"]').first()
  await expect(approveBtn, 'a pending request to approve').toBeVisible({ timeout: 10000 })
  await clickAndExpectApi(page, { urlIncludes: '/approve', method: 'POST' }, async () => {
    await approveBtn.click()
    await page.getByRole('button', { name: 'Confirmer' }).click()
  })

  // 3) Persistence: reload → the approved request is recorded (an "approved" chip appears).
  await page.goto('/company/workforce/leave', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByText(/Approuvé|Approved/).first()).toBeVisible({ timeout: 10000 })
})

test('unauthorized: employee cannot reach leave approval UI', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/leave', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  // RBAC: the admin leave page is permission-gated → redirected to 403 (not accessible).
  expect(page.url()).toContain('/403')
})

test('validation: inverted date range is rejected by the server (422)', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await requestLeave(page, '2027-09-10', '2027-09-05', 2) // date_to before date_from
  const [resp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('/workforce/me/leaves') && r.request().method() === 'POST'),
    page.locator('[data-test="me-leave-submit"]').click(),
  ])
  expect(resp.status()).toBe(422)
})
