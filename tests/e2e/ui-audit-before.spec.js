// @ts-check
// UI/UX AUDIT — "AVANT" reference captures (read-only: navigate + screenshot only).
// Run: pnpm exec playwright test tests/e2e/ui-audit-before.spec.js
// Output: tests/e2e/__screens__/ui-audit-before/*.png
import { test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers.js'

const DIR = 'tests/e2e/__screens__/ui-audit-before'
const DESKTOP = { width: 1440, height: 900 }
const MOBILE = { width: 390, height: 844 }

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(2500)
}
async function shot(page, name) {
  await settle(page)
  await page.screenshot({ path: `${DIR}/${name}.png`, fullPage: true }).catch(e => console.log('shot fail', name, e.message))
}

test.describe('UI audit — AVANT (company)', () => {
  test('company priority screens', async ({ page }) => {
    await page.setViewportSize(DESKTOP)
    await loginCompany(page)

    // 1. Company dashboard — the #1 target (desktop light)
    await page.goto('/dashboard'); await shot(page, '01-company-dashboard-desktop-light')

    // 2. Flat pages (desktop light)
    await page.goto('/company/audit').catch(() => {}); await shot(page, '02-company-audit-desktop')
    await page.goto('/company/workforce/payroll').catch(() => {}); await shot(page, '03-company-payroll-desktop')
    await page.goto('/company/documents/overview').catch(() => {}); await shot(page, '04-company-documents-desktop')

    // 3. Company dashboard — DARK (theme default is 'system' → emulate dark)
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.goto('/dashboard'); await shot(page, '05-company-dashboard-desktop-dark')
    await page.emulateMedia({ colorScheme: 'light' })

    // 4. Company dashboard — MOBILE
    await page.setViewportSize(MOBILE)
    await page.goto('/dashboard'); await shot(page, '06-company-dashboard-mobile')
    await page.goto('/company/workforce/payroll').catch(() => {}); await shot(page, '07-company-payroll-mobile')
  })
})

test.describe('UI audit — AVANT (platform)', () => {
  test('platform priority screens', async ({ page }) => {
    await page.setViewportSize(DESKTOP)
    await loginPlatform(page)
    await page.goto('/platform/dashboard/overview'); await shot(page, '10-platform-dashboard-desktop-light')
    await page.goto('/platform/companies'); await shot(page, '11-platform-companies-desktop')
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.goto('/platform/dashboard/overview'); await shot(page, '12-platform-dashboard-desktop-dark')
  })
})
