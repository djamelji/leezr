// @ts-check
import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi, apiRequest } from './helpers'

// Timesheet state machine driven by the manager (owner) on the isolated local base:
//   generate (draft) → submit → approve → lock, each a real API call + a UI status transition,
//   then prove "no modification after lock" at the API level (a further transition is refused 422).
// Replayable without migrate:fresh: a targeted fixture clears bob's timesheet period for the
// test window first. No bypass — the real submit/approve/lock use cases run.

const FROM = '2026-03-01'
const TO = '2026-03-31'

test.beforeAll(() => {
  execFileSync('php', ['artisan', 'e2e:reset-timesheets', '--employee=3', `--from=${FROM}`, `--to=${TO}`],
    { cwd: process.cwd(), stdio: 'pipe' })
})

async function setPicker(page, testid, iso) {
  const input = page.locator(`[data-test="${testid}"] input`).first()
  await input.waitFor({ state: 'attached', timeout: 10000 })
  await input.evaluate((el, d) => el._flatpickr.setDate(d, true), iso)
}

test('generate → submit → approve → lock → cannot modify after lock (API-enforced)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/time', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  // The generate action lives in the "Feuilles de temps" tab (default tab is "Pointages").
  await page.getByRole('tab', { name: 'Feuilles de temps' }).click()
  await page.waitForTimeout(800)

  // ─── Generate (draft) ──────────────────────────────────────────────────────
  await page.locator('[data-test="ts-generate-open"]').click()
  await page.waitForTimeout(500)
  await page.locator('[data-test="ts-gen-employee"]').click()
  await page.getByRole('option', { name: 'Bob Dupont' }).first().click()
  await setPicker(page, 'ts-gen-start', FROM)
  await setPicker(page, 'ts-gen-end', TO)
  const gen = await clickAndExpectApi(page, { urlIncludes: '/workforce/timesheets', method: 'POST' },
    () => page.locator('[data-test="ts-gen-submit"]').click())
  const period = await gen.json()
  const id = period.id
  expect(id, 'generated timesheet id').toBeTruthy()

  // ─── Submit → Approve → Lock on the detail page ─────────────────────────────
  await page.goto(`/company/workforce/time/${id}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  await clickAndExpectApi(page, { urlIncludes: `/timesheets/${id}/submit`, method: 'POST' },
    () => page.locator('[data-test="ts-submit"]').click())
  await page.waitForTimeout(800)

  await page.locator('[data-test="ts-approve"]').click()
  await clickAndExpectApi(page, { urlIncludes: `/timesheets/${id}/approve`, method: 'POST' },
    () => page.locator('[data-test="ts-approve-confirm"]').click())
  await page.waitForTimeout(800)

  await clickAndExpectApi(page, { urlIncludes: `/timesheets/${id}/lock`, method: 'POST' },
    () => page.locator('[data-test="ts-lock"]').click())
  await page.waitForTimeout(1000)

  // ─── Locked: no action controls remain (UI) ────────────────────────────────
  await page.goto(`/company/workforce/time/${id}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByText('Verrouillé').first(), 'status is Locked').toBeVisible({ timeout: 10000 })
  await expect(page.locator('[data-test="ts-submit"]')).toHaveCount(0)
  await expect(page.locator('[data-test="ts-approve"]')).toHaveCount(0)
  await expect(page.locator('[data-test="ts-lock"]')).toHaveCount(0)

  // ─── Locked: the API itself refuses a further transition (real enforcement) ──
  const reSubmit = await apiRequest(page, { method: 'POST', path: `/api/workforce/timesheets/${id}/submit` })
  expect(reSubmit.status, `submit on locked timesheet refused (got ${reSubmit.status})`).toBe(422)

  // ─── Persistence: still locked after reload (show wraps as { period, ... }) ──
  const showAgain = await apiRequest(page, { method: 'GET', path: `/api/workforce/timesheets/${id}` })
  expect(showAgain.json?.period?.status, 'persisted status is locked').toBe('locked')
})
