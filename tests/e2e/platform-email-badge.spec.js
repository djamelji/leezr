// @ts-check
// ADR-610 (LOT 2) — the Messaging nav item shows a live unread badge, reactive, even on a page
// other than the Inbox. Count is stubbed at the endpoint for determinism.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

const DASHBOARD = '/platform/dashboard/overview' // a page that is NOT the inbox

function stubCount(page, count) {
  return page.route('**/email/inbox/unread-count', r =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ count }) }),
  )
}

test('nav badge shows the unread count on a non-Inbox page', async ({ page }) => {
  await stubCount(page, 7)
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto(DASHBOARD, { waitUntil: 'networkidle' })

  const badge = page.locator('.layout-vertical-nav .nav-item-badge')
  await expect(badge.first()).toHaveText('7', { timeout: 8000 })
})

test('counts above 99 display as 99+', async ({ page }) => {
  await stubCount(page, 150)
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto(DASHBOARD, { waitUntil: 'networkidle' })

  await expect(page.locator('.layout-vertical-nav .nav-item-badge').first()).toHaveText('99+', { timeout: 8000 })
})

test('zero unread shows no badge', async ({ page }) => {
  await stubCount(page, 0)
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto(DASHBOARD, { waitUntil: 'networkidle' })

  // Give the nav time to render, then assert no badge in the vertical nav.
  await expect(page.locator('.layout-vertical-nav .nav-items').first()).toBeVisible({ timeout: 8000 })
  await expect(page.locator('.layout-vertical-nav .nav-item-badge')).toHaveCount(0)
})
