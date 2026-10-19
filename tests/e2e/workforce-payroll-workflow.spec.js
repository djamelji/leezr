// @ts-check
import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi, apiRequest } from './helpers'

// Payroll run lifecycle driven by the manager (owner) on the isolated local base:
//   create run → compute → (compute-calculations) → validate → export (JSON).
// Each step is a real API call + a UI status transition; final state persists.
// NO real payment is ever involved — the furthest step is a JSON export that only flips the
// run status to 'exported'. DSN submission and official payslips are explicitly NOT touched.
// Replayable via a targeted reset (e2e:reset-payroll).

const FROM = '2026-04-01'
const TO = '2026-04-30'

test.beforeAll(() => {
  execFileSync('php', ['artisan', 'e2e:reset-payroll', '--company=1', `--from=${FROM}`, `--to=${TO}`],
    { cwd: process.cwd(), stdio: 'pipe' })
  // Prepare real payroll data: a LOCKED timesheet for Bob (who has an active monthly
  // compensation plan) so compute produces a genuine payroll line → validate/export are reachable.
  execFileSync('php', ['artisan', 'e2e:prepare-payroll', '--employee=3', '--company=1', `--from=${FROM}`, `--to=${TO}`],
    { cwd: process.cwd(), stdio: 'pipe' })
})
test.beforeEach(() => test.setTimeout(120000))

async function setPicker(page, testid, iso) {
  const input = page.locator(`[data-test="${testid}"] input`).first()
  await input.waitFor({ state: 'attached', timeout: 10000 })
  await input.evaluate((el, d) => el._flatpickr.setDate(d, true), iso)
}

async function runStatus(page, id) {
  const res = await apiRequest(page, { method: 'GET', path: `/api/workforce/payroll/${id}` })

  return res.json?.status ?? res.json?.run?.status
}

test('create run → compute → validate → export (persisted, no payment)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/payroll', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  // ─── Create run ─────────────────────────────────────────────────────────────
  await page.locator('[data-test="pr-create-open"]').click()
  await page.waitForTimeout(500)
  await setPicker(page, 'pr-start', FROM)
  await setPicker(page, 'pr-end', TO)
  const created = await clickAndExpectApi(page, { urlIncludes: '/workforce/payroll', method: 'POST' },
    () => page.locator('[data-test="pr-create-submit"]').click())
  const run = await created.json()
  const id = run.id
  expect(id, 'created payroll run id').toBeTruthy()

  // Lands on the detail page (draft).
  await page.waitForTimeout(1500)
  expect(await runStatus(page, id), 'run starts draft').toBe('draft')

  // ─── Compute (draft → computed) ──────────────────────────────────────────────
  await page.goto(`/company/workforce/payroll/${id}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await clickAndExpectApi(page, { urlIncludes: `/payroll/${id}/compute`, method: 'POST' },
    () => page.locator('[data-test="pr-compute"]').click())
  await page.waitForTimeout(1500)
  expect(await runStatus(page, id), 'run computed').toBe('computed')

  // compute-calculations is conditional (only when no calculations yet); run it if present.
  await page.goto(`/company/workforce/payroll/${id}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const computeCalc = page.locator('[data-test="pr-compute-calc"]')
  if (await computeCalc.count()) {
    await clickAndExpectApi(page, { urlIncludes: `/payroll/${id}/compute-calculations`, method: 'POST' },
      () => computeCalc.click())
    await page.waitForTimeout(1500)
    await page.goto(`/company/workforce/payroll/${id}`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(1200)
  }

  // ─── Validate (computed → validated) ─────────────────────────────────────────
  // With error-level anomalies the backend (correctly) requires a validation note, so the
  // manager must justify the override — fill it before confirming.
  await page.locator('[data-test="pr-validate-open"]').click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="pr-validate-note"] input, [data-test="pr-validate-note"] textarea').first().fill('E2E: reviewed, anomalies acknowledged')
  await clickAndExpectApi(page, { urlIncludes: `/payroll/${id}/validate`, method: 'POST' },
    () => page.locator('[data-test="pr-validate-confirm"]').click())
  await page.waitForTimeout(1500)
  expect(await runStatus(page, id), 'run validated').toBe('validated')

  // ─── Export (validated → exported), JSON only — no payment ───────────────────
  await page.goto(`/company/workforce/payroll/${id}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await clickAndExpectApi(page, { urlIncludes: `/payroll/${id}/export`, method: 'POST' },
    () => page.locator('[data-test="pr-export"]').click())
  await page.waitForTimeout(1500)

  // ─── Persistence: the run is exported after reload ───────────────────────────
  expect(await runStatus(page, id), 'run exported (persisted)').toBe('exported')
})
