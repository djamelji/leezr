// @ts-check
// ADR-609 (recette) — search, and folder navigation (Inbox / Spam / Trash).
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('search filters the conversation list to the matching subject', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })
  await expect(page.locator('.email-item').first()).toBeVisible({ timeout: 8000 })

  await page.locator('.email-search input').fill('UNICORNTOKEN')
  // Debounced server search (400ms) → only the unique subject remains.
  await expect(page.locator('.email-item')).toHaveCount(1, { timeout: 8000 })
  await expect(page.locator('.email-item').first()).toContainText('UNICORNTOKEN')
})

test('folder navigation surfaces Spam and Trash items', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  await page.locator('[data-test="folder-spam"]').click()
  await expect(page.getByText('[E2E] Spam item').first()).toBeVisible({ timeout: 8000 })

  await page.locator('[data-test="folder-trash"]').click()
  await expect(page.getByText('[E2E] Trash item').first()).toBeVisible({ timeout: 8000 })

  // Back to inbox — the spam item must no longer be listed.
  await page.locator('[data-test="folder-inbox"]').click()
  await expect(page.getByText('[E2E] Spam item')).toHaveCount(0, { timeout: 8000 })
})
