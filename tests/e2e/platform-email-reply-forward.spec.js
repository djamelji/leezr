// @ts-check
// ADR-609 (recette) — reply-all and forward open the composer with the correct prefill.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

async function openThread(page, subject) {
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })
  await page.getByText(subject).first().click()
  await expect(page.locator('.email-body-content').first()).toBeVisible({ timeout: 8000 })
}

test('reply-all prefills To with the sender and keeps the subject threaded', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await openThread(page, '[E2E] Reply-all source')

  await page.locator('[data-test="thread-reply-all"]').click()

  const composer = page.locator('[data-test="email-compose"]')
  await expect(composer).toBeVisible({ timeout: 8000 })
  await expect(composer.locator('[data-test="compose-to"] input')).toHaveValue('client-a@example.org')
  await expect(composer.locator('[data-test="compose-subject"] input')).toHaveValue(/^Re: /)
})

test('forward opens the composer with a Fwd subject and empty recipient', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await openThread(page, '[E2E] Forward source')

  await page.locator('[data-test="thread-forward"]').click()

  const composer = page.locator('[data-test="email-compose"]')
  await expect(composer).toBeVisible({ timeout: 8000 })
  await expect(composer.locator('[data-test="compose-subject"] input')).toHaveValue(/^Fwd: /)
  await expect(composer.locator('[data-test="compose-to"] input')).toHaveValue('')
})
