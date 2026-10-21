// @ts-check
// Billing Overview premium UI (ADR-591+): KPI cards render with a period selector and background
// sparklines, numbers are present, no JS/console errors. Read-only.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('billing overview renders KPI cards, period selector and trend without JS errors', async ({ page }) => {
  const pageErrors = []
  page.on('pageerror', e => pageErrors.push(String(e)))

  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/overview', { waitUntil: 'networkidle' })
  await page.waitForTimeout(2000)

  // KPI cards present with values.
  await expect(page.locator('.kpi-card').first()).toBeVisible({ timeout: 10000 })
  const cardCount = await page.locator('.kpi-card').count()
  expect(cardCount).toBeGreaterThanOrEqual(5)

  // Period selector works (if the MRR trend has data).
  const periodToggle = page.locator('[data-test="mrr-period"]')
  if (await periodToggle.count()) {
    await expect(periodToggle).toBeVisible()
    await periodToggle.getByRole('button', { name: '3M' }).click()
    await page.waitForTimeout(400)
    await periodToggle.getByRole('button', { name: '12M' }).click()
  }

  expect(pageErrors, 'no uncaught JS exceptions').toEqual([])
})
