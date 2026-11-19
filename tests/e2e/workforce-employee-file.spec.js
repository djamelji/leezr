// @ts-check
// ADR-640 (W-C) — unified employee file: header + overview + conditional lazy sections. Real browser.
// ADR-831 (D-21 bis) — the employee list and file moved into Équipe (ADR-802/777/820) : the legacy URLs redirect to
// /company/people/hr and /company/people/employee-:id ; the former tabs are grouped into server-decided rubrics.
// Same checks : header, overview synthesis, profile + contract rubrics, a lazy section that renders content,
// no horizontal overflow, no JS error.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

test('unified employee file shows header, overview and conditional lazy sections', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
  await expect(page).toHaveURL(/\/company\/people\/hr/, { timeout: 10000 })

  // Open the first employee from the list.
  const row = page.locator('.v-data-table tbody tr').first()
  await expect(row).toBeVisible({ timeout: 10000 })
  await row.click()
  await expect(page).toHaveURL(/\/company\/people\/employee-\d+/, { timeout: 8000 })

  // Header + rubrics incl. Overview + Profile + Contracts.
  await expect(page.locator('[data-test="person-header"]')).toBeVisible({ timeout: 10000 })
  await expect(page.getByRole('tab', { name: /Vue d'ensemble/ })).toBeVisible({ timeout: 10000 })
  await expect(page.getByRole('tab', { name: /Informations & emploi/ })).toBeVisible()
  await expect(page.getByRole('tab', { name: /Documents & contrats/ })).toBeVisible()
  await page.screenshot({ path: test.info().outputPath('employee-file.png'), fullPage: false })

  // Overview synthesis is present (employment card).
  await expect(page.locator('[data-test="person-overview-employment"]')).toBeVisible()

  // Open a lazy section if available (Temps & absences → Congés) — must render content (not just a tab).
  const timeTab = page.getByRole('tab', { name: /Temps & absences/ })
  if (await timeTab.isVisible().catch(() => false)) {
    await timeTab.click()
    await expect(page.getByRole('button', { name: /Voir tout/ }).first()).toBeVisible({ timeout: 8000 })
  }

  // Dark mode + no overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
