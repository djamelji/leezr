// @ts-check
import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi, apiRequest } from './helpers'

// Leave LEDGER + BALANCE + PERMISSION workflow on the isolated local base:
//   - Employee (bob) requests leave (self-service), manager (owner) approves → the balance
//     RESERVES the requested days (available drops, reserved rises) — the real ledger effect.
//   - A rejected request writes NOTHING to the ledger (balance unchanged).
//   - Permission: the employee cannot approve/reject (ANO-014 — API refuses 403).
// Leaves are dated in the CURRENT year so accrual and reservation share one balance bucket,
// giving a clean, deterministic delta. Replayable via a targeted reset (e2e:reset-leave).

const TYPE_ID = 1 // Congés payés
const DAYS = 2
const HUNDREDTHS = DAYS * 100

test.beforeAll(() => {
  execFileSync('php', ['artisan', 'e2e:reset-leave', '--employee=3'], { cwd: process.cwd(), stdio: 'pipe' })
  execFileSync('php', ['artisan', 'e2e:prepare-leave-fixture', '--employee=3', '--leave-type=1', '--days=25'],
    { cwd: process.cwd(), stdio: 'pipe' })
})

async function bobRequests(page, { from, to, reason }) {
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)
  await page.locator('[data-test="me-request-leave"]').click()
  await page.waitForTimeout(500)
  // Type select → Congés payés
  await page.locator('[data-test="me-leave-type"]').click()
  await page.getByRole('option', { name: 'Congés payés' }).first().click()
  // The /me leave drawer uses plain AppTextField date inputs (not flatpickr).
  await page.locator('[data-test="me-leave-from"] input').fill(from)
  await page.locator('[data-test="me-leave-to"] input').fill(to)
  await page.locator('[data-test="me-leave-days"] input').fill(String(DAYS))
  await page.locator('[data-test="me-leave-reason"] textarea').first().fill(reason)
  const created = await clickAndExpectApi(page, { urlIncludes: '/workforce/me/leaves', method: 'POST' },
    () => page.locator('[data-test="me-leave-submit"]').click())
  expect(created.status(), 'self-service leave created').toBe(201)
}

// Read bob's balance for TYPE_ID in the current-year bucket, via owner's session.
async function readBalance(page) {
  const res = await apiRequest(page, { method: 'GET', path: '/api/workforce/employees/3/leave-balances' })
  expect(res.status, 'balances readable').toBe(200)
  const rows = Array.isArray(res.json) ? res.json : (res.json?.data ?? [])
  const row = rows.find(r => r.leave_type_id === TYPE_ID)

  return { available: row?.available ?? 0, reserved: row?.reserved ?? 0 }
}

async function findPendingLeaveId(page, reasonMarker) {
  const res = await apiRequest(page, { method: 'GET', path: '/api/workforce/leaves?status=pending' })
  const rows = Array.isArray(res.json) ? res.json : (res.json?.data ?? [])
  const match = rows.find(r => r.employee_id === 3 && (r.reason || '').includes(reasonMarker) && r.status === 'pending')

  return match?.id
}

test('approve reserves balance; reject leaves it unchanged; employee cannot approve', async ({ browser }) => {
  // Two long-lived sessions (one login each) to avoid the login throttle on replay.
  const bobCtx = await browser.newContext()
  const ownerCtx = await browser.newContext()
  const bob = await bobCtx.newPage()
  const owner = await ownerCtx.newPage()
  try {
    await loginCompany(bob, 'bob@leezr.test')
    await loginCompany(owner, 'owner@leezr.test')
    await owner.goto('/company/workforce/leave', { waitUntil: 'networkidle' })
    await owner.waitForTimeout(800)

    // ── 1) Employee requests (self-service); 2) manager approves → reservation ──
    await bobRequests(bob, { from: '2026-12-07', to: '2026-12-08', reason: 'E2E ledger approve' })
    const before = await readBalance(owner)
    const approveId = await findPendingLeaveId(owner, 'E2E ledger approve')
    expect(approveId, 'pending approve request found').toBeTruthy()

    const approve = await apiRequest(owner, { method: 'POST', path: `/api/workforce/leaves/${approveId}/approve` })
    expect(approve.status, `owner approves (got ${approve.status})`).toBeLessThan(400)

    const afterApprove = await readBalance(owner)
    expect(afterApprove.available, 'available dropped by the reserved days').toBe(before.available - HUNDREDTHS)
    expect(afterApprove.reserved, 'reserved rose by the requested days').toBe(before.reserved + HUNDREDTHS)

    // ── 3) A rejected request writes nothing to the ledger ────────────────────
    await bobRequests(bob, { from: '2026-12-20', to: '2026-12-21', reason: 'E2E ledger reject' })
    const beforeReject = await readBalance(owner)
    const rejectId = await findPendingLeaveId(owner, 'E2E ledger reject')
    expect(rejectId, 'pending reject request found').toBeTruthy()

    const reject = await apiRequest(owner, { method: 'POST', path: `/api/workforce/leaves/${rejectId}/reject`, body: { review_note: 'E2E rejected' } })
    expect(reject.status, `owner rejects (got ${reject.status})`).toBeLessThan(400)

    const afterReject = await readBalance(owner)
    expect(afterReject.available, 'rejection does not change available').toBe(beforeReject.available)
    expect(afterReject.reserved, 'rejection does not change reserved').toBe(beforeReject.reserved)

    // ── 4) Permission: the employee cannot approve/reject (ANO-014) ───────────
    const bobApprove = await apiRequest(bob, { method: 'POST', path: `/api/workforce/leaves/${approveId}/approve` })
    expect(bobApprove.status, 'employee forbidden to approve leave').toBe(403)
  } finally {
    await bobCtx.close()
    await ownerCtx.close()
  }
})
