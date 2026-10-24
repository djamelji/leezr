// @ts-check
// ADR-608 (LOT 6) — the conversation list is fully keyboard-navigable: ArrowDown highlights a row,
// Enter opens it. No mouse required.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('conversation list is keyboard-navigable (arrow + enter)', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  const list = page.locator('.email-list')
  await expect(list.locator('.email-item').first()).toBeVisible({ timeout: 8000 })

  // Move focus to the list and highlight the first row.
  await list.focus()
  await page.keyboard.press('ArrowDown')
  await expect(list.locator('.email-item.is-active')).toHaveCount(1)

  // Enter opens the highlighted conversation → the reading pane renders a message body.
  await page.keyboard.press('Enter')
  await expect(page.locator('.email-body-content').first()).toBeVisible({ timeout: 8000 })
})
