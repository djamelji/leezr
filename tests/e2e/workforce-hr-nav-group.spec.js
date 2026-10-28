// @ts-check
// ADR-635 (W-A) — the company sidebar shows a "Ressources humaines" group containing the Workforce
// entries (manifest-driven), entries route correctly, and the group renders in dark mode.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('HR nav group renders with workforce entries and routes correctly', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce', { waitUntil: 'networkidle' })

  const nav = page.locator('.layout-vertical-nav')

  // The "Ressources humaines" group heading is present (renamed from generic "Entreprise").
  await expect(nav.getByText('Ressources humaines', { exact: false }).first()).toBeVisible({ timeout: 10000 })

  // A Workforce entry lives in the sidebar and routes to its workforce page.
  const employeesLink = nav.locator('a[href*="/company/workforce/employees"]').first()
  await expect(employeesLink).toBeVisible()
  await employeesLink.click()
  await expect(page).toHaveURL(/\/company\/workforce\/employees/, { timeout: 8000 })

  await page.screenshot({ path: `${SCRATCH}/hr-nav.png`, fullPage: false })

  // Dark mode: group still renders, no horizontal overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await expect(nav.getByText('Ressources humaines', { exact: false }).first()).toBeVisible()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
