// @ts-check
// ADR-609 (recette) — mobile (390px) and dark mode render the mailbox without breakage.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('mobile 390px exposes the folder drawer via the hamburger', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  // At smAndDown the hamburger is shown; the folder list is reachable through it.
  const hamburger = page.locator('[data-test="email-mobile-menu"]')
  await expect(hamburger).toBeVisible({ timeout: 8000 })
  await hamburger.click()
  await expect(page.locator('[data-test="folder-inbox"]').first()).toBeVisible({ timeout: 8000 })
})

test('dark mode applies and the mailbox stays readable', async ({ page }) => {
  // theme = 'system' by default → emulate a dark OS preference to force the dark Vuetify theme.
  await page.emulateMedia({ colorScheme: 'dark' })
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  const isDark = await page.evaluate(() => !!document.querySelector('.v-theme--dark'))
  expect(isDark).toBeTruthy()
  await expect(page.locator('.email-item').first()).toBeVisible({ timeout: 8000 })
})
