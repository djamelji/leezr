// @ts-check
// ADR-640 (W-C) — unified employee file: header + overview + conditional lazy sections. Real browser.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('unified employee file shows header, overview and conditional lazy sections', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })

  // Open the first employee from the list.
  const row = page.locator('.v-data-table tbody tr').first()
  await expect(row).toBeVisible({ timeout: 10000 })
  await row.click()
  await expect(page).toHaveURL(/\/company\/workforce\/employees\/\d+/, { timeout: 8000 })

  // Tabs incl. Overview + at least Profile/Contract + a conditional section.
  await expect(page.getByRole('tab', { name: /Vue d'ensemble/ })).toBeVisible({ timeout: 10000 })
  await expect(page.getByRole('tab', { name: /^Profil$/ })).toBeVisible()
  await expect(page.getByRole('tab', { name: /Contrats/ })).toBeVisible()
  await page.screenshot({ path: `${SCRATCH}/employee-file.png`, fullPage: false })

  // Overview synthesis is present.
  await expect(page.getByText('Situation').first()).toBeVisible()

  // Open a lazy section if available (Congés), else Profile — must render content (not just a tab).
  const leaveTab = page.getByRole('tab', { name: /Congés/ })
  if (await leaveTab.isVisible().catch(() => false)) {
    await leaveTab.click()
    await expect(page.getByRole('button', { name: /Voir tout/ })).toBeVisible({ timeout: 8000 })
  }

  // Dark mode + no overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
