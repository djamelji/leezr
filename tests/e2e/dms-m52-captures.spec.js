// @ts-check
// ADR-764 (DMS M5.2) — captures : éditeur de revue (incertitude expliquée + actions).
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dms-m52'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1600)
}

test('DMS M5.2 — éditeur de revue (light/dark/mobile)', async ({ page }) => {
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1500 })

  await page.goto('/company/documents/payslips')
  await settle(page)
  try {
    await page.getByRole('button', { name: /revoir|review/i }).first().click({ timeout: 4000 })
    await settle(page)
  } catch { /* */ }
  await page.screenshot({ path: `${DIR}/01-review-editor-light.png`, fullPage: true })

  await page.setViewportSize({ width: 390, height: 1400 })
  await settle(page)
  await page.screenshot({ path: `${DIR}/02-review-editor-mobile.png`, fullPage: true })

  await page.setViewportSize({ width: 1440, height: 1500 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/company/documents/payslips')
  await settle(page)
  try {
    await page.getByRole('button', { name: /revoir|review/i }).first().click({ timeout: 4000 })
    await settle(page)
  } catch { /* */ }
  await page.screenshot({ path: `${DIR}/01-review-editor-dark.png`, fullPage: true })
})
