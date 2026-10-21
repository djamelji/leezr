// @ts-check
// M4-D — public price-change governance dialog (ADR-588). Real UI → API: editing a plan's public
// price shows the impact (how many existing subscribers keep their contracted price) before publish.
// Read-only: cancels instead of publishing, so no plan price is actually changed.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('editing a plan public price shows the governance impact before publishing', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/plans/pro', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  const field = page.locator('[data-test="plan-price-monthly"] input')
  await expect(field).toBeVisible({ timeout: 10000 })

  // Cross-check the impact endpoint (real affected count).
  const impact = await page.evaluate(async () => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const r = await fetch('/api/platform/plans/pro/pricing-impact?interval=monthly&proposed_cents=99900', { headers: { 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''), Accept: 'application/json' }, credentials: 'include' })

    return r.status === 200 ? r.json() : null
  })
  expect(impact, 'impact endpoint reachable').not.toBeNull()
  expect(Number.isInteger(impact.affected_subscriptions)).toBe(true)

  // Change the public price and submit → the governance dialog must appear (not a silent save).
  await field.fill('999')
  await page.locator('[data-test="plan-save-commercial"]').click()
  await expect(page.locator('[data-test="price-governance-dialog"]'), 'governance dialog shown').toBeVisible({ timeout: 8000 })
  await expect(page.locator('[data-test="pg-impact"]')).toBeVisible()
  // The dialog states the real number of existing subscribers who keep their contracted price.
  await expect(page.locator('[data-test="pg-impact"]')).toContainText(String(impact.affected_subscriptions))

  // Cancel — do not actually change the price (read-only verification).
  await page.getByRole('button', { name: /Annuler|Cancel/i }).click()
  await expect(page.locator('[data-test="price-governance-dialog"]')).toBeHidden()
})
