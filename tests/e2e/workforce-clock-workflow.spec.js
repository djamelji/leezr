// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'

// Self-service time-clock workflow (bob, employee): clock-in → break → resume → clock-out.
// Each step is a real API call + a state transition reflected in the UI. Requires a clean
// clock day (migrate:fresh --seed resets it). Real endpoints, no bypass.
test('clock-in → break → resume → clock-out (self-service)', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  const btn = name => page.getByRole('button', { name })

  const clockIn = btn('Commencer la journée')
  if (!(await clockIn.count())) test.skip(true, 'Clock already started today (needs a fresh seed to re-run)')

  // 1) Clock in → working.
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/clock-in', method: 'POST' },
    () => clockIn.click())
  await expect(btn('Début pause')).toBeVisible({ timeout: 10000 })

  // 2) Start break → on break.
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/break/start', method: 'POST' },
    () => btn('Début pause').click())
  await expect(btn('Fin pause')).toBeVisible({ timeout: 10000 })

  // 3) Resume (end break) → working.
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/break/end', method: 'POST' },
    () => btn('Fin pause').click())
  await expect(btn('Terminer la journée')).toBeVisible({ timeout: 10000 })

  // 4) Clock out → completed.
  await clickAndExpectApi(page, { urlIncludes: '/workforce/me/clock-out', method: 'POST' },
    () => btn('Terminer la journée').click())

  // Persistence: reload → the day is completed (no clock-in button to start again).
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(btn('Commencer la journée')).toHaveCount(0)
})
