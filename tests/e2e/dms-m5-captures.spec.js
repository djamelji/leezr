// @ts-check
// ADR-762 (DMS M5) — captures : centre « Import de bulletins » (lots + revue des correspondances).
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dms-m5'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1600)
}

test('DMS M5 — import bulletins (lots + revue) light/dark/mobile', async ({ page }) => {
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1500 })

  // 1. Onglet Import bulletins
  await page.goto('/company/documents/payslips')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-payslip-import-light.png`, fullPage: true })

  // 2. Revue du lot (correspondances)
  try {
    await page.getByRole('button', { name: /revoir|review/i }).first().click({ timeout: 4000 })
    await settle(page)
  } catch { /* */ }
  await page.screenshot({ path: `${DIR}/02-payslip-review-light.png`, fullPage: true })

  // Mobile + dark (liste)
  await page.setViewportSize({ width: 390, height: 1400 })
  await page.goto('/company/documents/payslips')
  await settle(page)
  await page.screenshot({ path: `${DIR}/03-payslip-import-mobile.png`, fullPage: true })

  await page.setViewportSize({ width: 1440, height: 1500 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/company/documents/payslips')
  await settle(page)
  try {
    await page.getByRole('button', { name: /revoir|review/i }).first().click({ timeout: 4000 })
    await settle(page)
  } catch { /* */ }
  await page.screenshot({ path: `${DIR}/02-payslip-review-dark.png`, fullPage: true })
})
