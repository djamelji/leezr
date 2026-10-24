// @ts-check
// ADR-610 (LOT 4) — the unread badge animates discreetly on appearance, and all email animations
// honour prefers-reduced-motion. Deterministic (count stubbed; no SSE needed).
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

const DASHBOARD = '/platform/dashboard/overview'

function stubCount(page, count) {
  return page.route('**/email/inbox/unread-count', r =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ count }) }),
  )
}

test('nav badge plays a discrete pop animation on appearance', async ({ page }) => {
  await stubCount(page, 5)
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto(DASHBOARD, { waitUntil: 'networkidle' })

  const badge = page.locator('.layout-vertical-nav .nav-item-badge').first()
  await expect(badge).toBeVisible({ timeout: 8000 })
  const anim = await badge.evaluate(el => getComputedStyle(el).animationName)
  expect(anim).toBe('nav-badge-pop')
})

test('prefers-reduced-motion disables the badge animation', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await stubCount(page, 5)
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto(DASHBOARD, { waitUntil: 'networkidle' })

  const badge = page.locator('.layout-vertical-nav .nav-item-badge').first()
  await expect(badge).toBeVisible({ timeout: 8000 })
  const anim = await badge.evaluate(el => getComputedStyle(el).animationName)
  expect(anim).toBe('none')
})
