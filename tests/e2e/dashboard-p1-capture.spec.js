// @ts-check
// P1 — captures APRÈS sur le VRAI dashboard Company (read-only).
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dashboard-p1-after'
async function settle(page) { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(3500) }

test('company dashboard — après P1', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page)
  await page.goto('/dashboard'); await settle(page)
  await page.screenshot({ path: `${DIR}/01-desktop-light.png`, fullPage: true })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/dashboard'); await settle(page)
  await page.screenshot({ path: `${DIR}/02-desktop-dark.png`, fullPage: true })
  await page.emulateMedia({ colorScheme: 'light' })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/dashboard'); await settle(page)
  await page.screenshot({ path: `${DIR}/03-mobile-light.png`, fullPage: true })
})
