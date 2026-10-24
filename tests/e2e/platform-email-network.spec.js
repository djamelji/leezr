// @ts-check
// ADR-609 — on a send network/SMTP failure the composer stays open with the typed content intact
// (no silent content loss); the user can retry.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('failed send keeps the composer open and the content preserved', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  // Force the compose request to fail.
  await page.route('**/email/inbox/compose', route => route.fulfill({ status: 500, body: '{"message":"boom"}', contentType: 'application/json' }))

  const token = `NETFAIL-${Date.now()}`
  await page.locator('[data-test="email-compose-btn"]').click()
  const composer = page.locator('[data-test="email-compose"]')
  await expect(composer).toBeVisible({ timeout: 8000 })
  await composer.locator('[data-test="compose-to"] input').fill('netfail@example.org')
  await composer.locator('[data-test="compose-subject"] input').fill(token)
  await composer.locator('[data-test="compose-body"] .ProseMirror').click()
  await page.keyboard.type('Message qui doit survivre à un échec réseau.')

  await composer.locator('[data-test="compose-send"]').click()

  // The composer must remain open and still carry the subject (content not lost).
  await expect(composer).toBeVisible({ timeout: 8000 })
  await expect(composer.locator('[data-test="compose-subject"] input')).toHaveValue(token)
})
