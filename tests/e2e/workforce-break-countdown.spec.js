// @ts-check
import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'

// LOT C — break countdown driven by the SERVER + rules engine (never Vue constants).
// Full flow: clock-in → break → countdown → refresh (persists) → resume → clock-out.
// Plus: the countdown target follows the per-Company resolved break config.

function reset(targetMinutes) {
  execFileSync('php', ['artisan', 'e2e:reset-clock-day', '--employee=3'], { cwd: process.cwd(), stdio: 'pipe' })
  execFileSync('php', ['artisan', 'e2e:set-break-target', '--company=1', `--minutes=${targetMinutes}`], { cwd: process.cwd(), stdio: 'pipe' })
}

function parseClock(txt) {
  const m = (txt || '').match(/(\d+):(\d{2})/)

  return m ? Number(m[1]) * 60 + Number(m[2]) : NaN
}

test('clock-in → break → countdown → refresh → resume → clock-out (resolver-driven, persistent)', async ({ page }) => {
  reset(30)
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  // Clock in.
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/clock-in', method: 'POST' },
    () => page.locator('[data-test="me-clock-in"]').click())
  await expect(page.locator('[data-test="me-start-break"]')).toBeVisible({ timeout: 10000 })

  // Start break → countdown panel appears, target = 30:00 (from the resolved company config).
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/break/start', method: 'POST' },
    () => page.locator('[data-test="me-start-break"]').click())
  const panel = page.locator('[data-test="me-break-panel"]')
  await expect(panel).toBeVisible({ timeout: 10000 })
  await expect(panel, 'target comes from the resolver (30 min)').toContainText('30:00')
  await expect(page.locator('[data-test="me-break-remaining"]')).toBeVisible()

  // Elapsed ticks up from the server break start.
  await page.waitForTimeout(3000)
  const elapsedBefore = parseClock(await page.locator('[data-test="me-break-elapsed"]').innerText())
  expect(elapsedBefore, 'elapsed advancing').toBeGreaterThanOrEqual(2)

  // Refresh — the break state + elapsed must persist (recomputed from server start_at).
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.locator('[data-test="me-break-panel"]'), 'break panel survives refresh').toBeVisible({ timeout: 10000 })
  const elapsedAfter = parseClock(await page.locator('[data-test="me-break-elapsed"]').innerText())
  expect(elapsedAfter, 'elapsed NOT reset after refresh (>= before)').toBeGreaterThanOrEqual(elapsedBefore)
  await expect(page.locator('[data-test="me-break-panel"]')).toContainText('30:00')

  // Resume (policy default 'warn' allows resume even below the legal minimum).
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/break/end', method: 'POST' },
    () => page.locator('[data-test="me-end-break"]').click())
  await expect(page.locator('[data-test="me-start-break"]'), 'back to working').toBeVisible({ timeout: 10000 })

  // Clock out → completed.
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/clock-out', method: 'POST' },
    () => page.locator('[data-test="me-clock-out"]').click())
  await page.waitForTimeout(1000)
  await expect(page.locator('[data-test="me-clock-in"]')).toHaveCount(0)
})

test('break target is resolver-driven: changing the company config changes the countdown', async ({ page }) => {
  reset(45)
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/clock-in', method: 'POST' },
    () => page.locator('[data-test="me-clock-in"]').click())
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/break/start', method: 'POST' },
    () => page.locator('[data-test="me-start-break"]').click())

  // Target reflects the 45-min company configuration (server-resolved, not a Vue constant).
  await expect(page.locator('[data-test="me-break-panel"]')).toContainText('45:00', { timeout: 10000 })
})
