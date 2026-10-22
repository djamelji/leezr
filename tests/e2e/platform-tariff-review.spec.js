// @ts-check
// ADR-594 — "Subscriptions to verify" (tariff review) in the Platform billing cockpit.
// Real UI → API → DB: the tab renders (table or empty state), the review endpoint is reachable
// for an authorized admin, proof is server-enforced, and the endpoint rejects unauthenticated access.
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany, loginPlatform } from './helpers'

test('tariff review tab renders and exposes an authorized, proof-guarded confirmation API', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/tariff-review', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  // Tab mounts; either the table (unverified subs present) or the success empty state is shown.
  await expect(page.locator('[data-test="billing-tariff-review-tab"]')).toBeVisible({ timeout: 8000 })
  const hasTable = await page.locator('[data-test="tr-table"]').count()
  const hasEmpty = await page.locator('[data-test="tr-empty"]').count()
  expect(hasTable + hasEmpty, 'table or empty-state rendered').toBeGreaterThan(0)

  // UI → API: the review list is reachable for an authorized admin.
  const list = await apiRequest(page, { method: 'GET', path: '/api/platform/pricing/review-subscriptions' })
  expect(list.status, 'admin can read the review list').toBe(200)
  expect(Array.isArray(list.json?.subscriptions), 'response carries a subscriptions array').toBe(true)

  // Proof is server-enforced: a confirmation without proof is refused (422), never silently accepted.
  const noProof = await apiRequest(page, {
    method: 'POST',
    path: '/api/platform/pricing/confirm-price',
    body: { subscription_id: 1, price_cents: 2900 },
  })
  expect(noProof.status, 'missing proof → validation error').toBe(422)
})

test('tariff review API rejects a non-platform session', async ({ page }) => {
  // A regular company user must not reach the platform tariff-review endpoint.
  await loginCompany(page, 'owner@leezr.test')
  const res = await apiRequest(page, { method: 'GET', path: '/api/platform/pricing/review-subscriptions' })
  expect([401, 403], `company session blocked (got ${res.status})`).toContain(res.status)
})
