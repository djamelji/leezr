// @ts-check
// ADR-745 — capture dashboard Company VIDE (empty states, onboarding, pas de KPI fictif).
// Bascule vers une company sans données via le cookie currentCompanyId.
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dashboard-p1-after'
const EMPTY_COMPANY_ID = '2'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(2000)
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
  await page.waitForTimeout(700)
  await page.evaluate(() => window.scrollTo(0, 0))
  await page.waitForTimeout(1000)
}

test('dashboard company vide', async ({ page, context }) => {
  await loginCompany(page)
  // Bascule vers la company vide
  await context.addCookies([{ name: 'currentCompanyId', value: EMPTY_COMPANY_ID, domain: 'leezr.test', path: '/' }])

  await page.setViewportSize({ width: 1440, height: 2000 })
  await page.goto('/dashboard')
  await settle(page)
  await page.screenshot({ path: `${DIR}/06-empty-company-light.png` })

  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/dashboard')
  await settle(page)
  await page.screenshot({ path: `${DIR}/07-empty-company-dark.png` })
})
