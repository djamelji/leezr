// @ts-check
// Phase 0 — captures de la page de référence Leezr Premium (read-only).
// Run: pnpm exec playwright test tests/e2e/ui-lab-capture.spec.js
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/ui-lab'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(2500) // laisse les charts ApexCharts s'animer
}

test('ui-lab reference page — all viewports & themes', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page)

  // Desktop light
  await page.goto('/ui-lab')
  await settle(page)
  await expect(page.locator('text=Design System Leezr Premium')).toBeVisible()
  await page.screenshot({ path: `${DIR}/01-desktop-light.png`, fullPage: true })

  // Desktop dark (theme default 'system' → emulate dark)
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/ui-lab'); await settle(page)
  await page.screenshot({ path: `${DIR}/02-desktop-dark.png`, fullPage: true })
  await page.emulateMedia({ colorScheme: 'light' })

  // Tablet
  await page.setViewportSize({ width: 834, height: 1112 })
  await page.goto('/ui-lab'); await settle(page)
  await page.screenshot({ path: `${DIR}/03-tablet-light.png`, fullPage: true })

  // Mobile
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/ui-lab'); await settle(page)
  await page.screenshot({ path: `${DIR}/04-mobile-light.png`, fullPage: true })

  // Mobile dark
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/ui-lab'); await settle(page)
  await page.screenshot({ path: `${DIR}/05-mobile-dark.png`, fullPage: true })
})
