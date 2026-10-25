// @ts-check
// ADR-629 (LOT 7 sub-lots 1-2) — Platform financial cockpit + subscriptions/tariff-review render
// for an authorized admin, no JS errors. Blocked renewals (ADR-593) are surfaced in tariff-review.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

async function open(page, path) {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto(path, { waitUntil: 'networkidle' })

  return errors
}

test('platform billing cockpit (dashboard) renders without JS errors', async ({ page }) => {
  const errors = await open(page, '/platform/billing/overview')
  await expect(page.locator('.v-tab').first()).toBeVisible({ timeout: 10000 })
  await expect(page.locator('.v-card, .widget-root').first()).toBeVisible({ timeout: 10000 })
  // ADR-631: the blocked-renewals KPI card is actually present (content, not just render).
  await expect(page.locator('[class*="lock-exclamation"]').first()).toBeVisible({ timeout: 10000 })
  expect(errors, errors.join(' | ')).toHaveLength(0)
})

test('tariff-review tab surfaces blocked renewals (or empty) without JS errors', async ({ page }) => {
  const errors = await open(page, '/platform/billing/tariff-review')
  await expect(page.locator('.v-tab').first()).toBeVisible({ timeout: 10000 })
  // table of subscriptions under review OR an empty state — never a crash.
  await expect(page.locator('.v-data-table, .v-card, .v-table, .text-center').first()).toBeVisible({ timeout: 10000 })
  expect(errors, errors.join(' | ')).toHaveLength(0)
})

test('subscriptions tab renders without JS errors', async ({ page }) => {
  const errors = await open(page, '/platform/billing/subscriptions')
  await expect(page.locator('.v-tab').first()).toBeVisible({ timeout: 10000 })
  expect(errors, errors.join(' | ')).toHaveLength(0)
})
