// @ts-check
// ADR-763 (DMS M5.1) — captures : « Mes bulletins » self-service + revue d'import (multipage).
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dms-m51'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1600)
}

test('DMS M5.1 — Mes bulletins + revue import (light/dark/mobile)', async ({ page }) => {
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1500 })

  // 1. Mes bulletins (self-service)
  await page.goto('/account-settings/documents')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-my-payslips-light.png`, fullPage: true })

  // 2. Mobile
  await page.setViewportSize({ width: 390, height: 1400 })
  await settle(page)
  await page.screenshot({ path: `${DIR}/02-my-payslips-mobile.png`, fullPage: true })

  // 3. Dark
  await page.setViewportSize({ width: 1440, height: 1500 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/account-settings/documents')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-my-payslips-dark.png`, fullPage: true })

  // 4. Centre de revue d'import (rappel)
  await page.emulateMedia({ colorScheme: 'light' })
  await page.goto('/company/documents/payslips')
  await settle(page)
  await page.screenshot({ path: `${DIR}/03-import-review.png`, fullPage: true })
})
