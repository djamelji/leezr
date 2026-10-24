// @ts-check
// ADR-606 (LOT 7) — untrusted inbound HTML is neutralized (no XSS). Uses a locally-seeded thread
// whose body contains <script> and an onerror handler; neither must execute, the markup is stripped,
// and the safe text survives.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('malicious inbound HTML is sanitized before rendering', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  // Open the seeded malicious thread from the list.
  await page.getByText('XSS Probe Subject').first().click()
  await expect(page.locator('.email-body-content').first()).toBeVisible({ timeout: 8000 })

  // Safe text preserved…
  await expect(page.locator('.email-body-content').first()).toContainText('Safe content here.')

  // …but the <script> was stripped and the onerror handler never fired.
  expect(await page.locator('.email-body-content script').count()).toBe(0)
  expect(await page.evaluate(() => window.__xss)).toBeFalsy()
})
