// @ts-check
import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

// CONFIRMED BUG fix — the navbar worked-time counter must NOT reset to zero after a page refresh.
// A fixture puts bob into a WORKING state with clock_in 25 min ago; the server timestamp is the
// source of truth, so the counter must read ~25 min and keep it across reload (it is recomputed
// from clock_in, never from an in-memory JS counter). Replayable without migrate:fresh.

test.beforeAll(() => {
  execFileSync('php', ['artisan', 'e2e:prepare-clock', '--employee=3', '--minutes-ago=25'],
    { cwd: process.cwd(), stdio: 'pipe' })
})

async function openWidgetAndReadMinutes(page) {
  await page.locator('[data-test="wfc-widget-btn"]').click()
  const el = page.locator('[data-test="wfc-worked-time"]')
  await expect(el).toBeVisible({ timeout: 10000 })
  const txt = (await el.innerText()).trim() // "0h25"
  await page.keyboard.press('Escape') // close the menu
  const m = txt.match(/(\d+)h(\d+)/)

  return m ? Number(m[1]) * 60 + Number(m[2]) : NaN
}

test('navbar worked-time counter survives a page refresh (no reset to zero)', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  // bob is a Driver (workspace 'home'); use his self-service clock page where the navbar
  // widget is present. The widget + page share the same store (single business model).
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  const before = await openWidgetAndReadMinutes(page)
  expect(before, 'counter reads ~25 min from server clock_in').toBeGreaterThanOrEqual(24)
  expect(before).toBeLessThanOrEqual(27)

  // Refresh — the bug was: counter resets to 0h00 here.
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  const after = await openWidgetAndReadMinutes(page)
  expect(after, 'counter is NOT reset to zero after refresh').toBeGreaterThanOrEqual(24)
  expect(after, 'counter preserved (±1 min) across refresh').toBeLessThanOrEqual(28)
})
