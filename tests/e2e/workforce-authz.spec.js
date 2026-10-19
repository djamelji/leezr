// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

// ANO-014 non-regression: workforce MANAGEMENT endpoints must enforce granular permissions
// server-side, not just module access. A self-service employee (bob, Driver role) holds the
// workforce module (for /me) but NO management permission, so the API must refuse (403) while
// the owner (bypass) is allowed (200/<400). Probes the real session — no bypass.

const MANAGEMENT = [
  { method: 'GET', path: '/api/workforce/employees', label: 'list employees' },
  { method: 'POST', path: '/api/workforce/employees', body: { first_name: 'X', last_name: 'Y', hire_date: '2026-01-01' }, label: 'create employee' },
  { method: 'POST', path: '/api/workforce/timesheets', body: { employee_id: 3, period_start: '2026-05-01', period_end: '2026-05-31' }, label: 'generate timesheet' },
  { method: 'GET', path: '/api/workforce/leaves', label: 'list leave requests' },
  { method: 'GET', path: '/api/workforce/payroll', label: 'list payroll runs' },
  { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: 3, date: '2026-01-07', start_at: '2026-01-07T09:00:00', end_at: '2026-01-07T17:00:00', timezone: 'Europe/Paris' }, label: 'create shift' },
]

test('employee (no permission) is refused on every workforce management endpoint', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)

  for (const ep of MANAGEMENT) {
    const res = await apiRequest(page, { method: ep.method, path: ep.path, body: ep.body ?? null })
    expect(res.status, `bob must be forbidden to ${ep.label} (got ${res.status})`).toBe(403)
  }
})

test('owner (bypass) is allowed on the same endpoints', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)

  const list = await apiRequest(page, { method: 'GET', path: '/api/workforce/employees' })
  expect(list.status, 'owner can list employees').toBe(200)
  const leaves = await apiRequest(page, { method: 'GET', path: '/api/workforce/leaves' })
  expect(leaves.status, 'owner can list leave requests').toBe(200)
  const payroll = await apiRequest(page, { method: 'GET', path: '/api/workforce/payroll' })
  expect(payroll.status, 'owner can list payroll runs').toBe(200)
})
