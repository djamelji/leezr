// @ts-check
// ADR-604 (LOT 3) — composer keyboard flow. Mouse-free: To → Subject → Body by Tab; Cc/Bcc insert
// into the flow when shown; Cmd/Ctrl+Shift+C/B toggle Cc/Bcc; Cmd/Ctrl+Enter sends.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

async function openComposer(page) {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })
  await page.locator('[data-test="email-compose-btn"]').click()
  await expect(page.locator('[data-test="email-compose"]')).toBeVisible({ timeout: 8000 })
}

test('Tab flows To → Subject → Body when Cc/Bcc are hidden', async ({ page }) => {
  await openComposer(page)
  const toInput = page.locator('[data-test="compose-to"] input')
  await toInput.click()
  await toInput.fill('client@acme.com')

  await page.keyboard.press('Tab')
  await expect(page.locator('[data-test="compose-subject"] input')).toBeFocused()

  // Tab from Subject lands directly in the body editor (the @core Tiptap toolbar is skipped).
  await page.keyboard.press('Tab')
  await expect(page.locator('[data-test="compose-body"] .ProseMirror').first()).toBeFocused()
})

test('Cc/Bcc keyboard shortcuts insert the fields into the tab flow', async ({ page }) => {
  await openComposer(page)
  const toInput = page.locator('[data-test="compose-to"] input')
  await toInput.click()
  await toInput.fill('client@acme.com')

  // Toggle Cc + Bcc via keyboard shortcuts (no mouse).
  const mod = process.platform === 'darwin' ? 'Meta' : 'Control'
  await page.keyboard.press(`${mod}+Shift+C`)
  await page.keyboard.press(`${mod}+Shift+B`)
  await expect(page.locator('[data-test="compose-cc"] input')).toBeVisible()
  await expect(page.locator('[data-test="compose-bcc"] input')).toBeVisible()

  // Now Tab order is To → Cc → Bcc → Subject → Body.
  await toInput.click()
  await page.keyboard.press('Tab')
  await expect(page.locator('[data-test="compose-cc"] input')).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(page.locator('[data-test="compose-bcc"] input')).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(page.locator('[data-test="compose-subject"] input')).toBeFocused()
})

test('Cmd/Ctrl+Enter sends and closes the composer (fully keyboard)', async ({ page }) => {
  await openComposer(page)
  await page.locator('[data-test="compose-to"] input').fill('client@acme.com')
  await page.locator('[data-test="compose-subject"] input').fill('Keyboard send')
  const body = page.locator('[data-test="compose-body"] .ProseMirror')
  await body.click()
  await body.type('Sent entirely with the keyboard.')

  const mod = process.platform === 'darwin' ? 'Meta' : 'Control'
  await page.keyboard.press(`${mod}+Enter`)
  await expect(page.locator('[data-test="email-compose"]')).toBeHidden({ timeout: 8000 })
})
