// @ts-check
// M4-C — tariff migration wizard (ADR-588). Real UI → API: open → select → preview (no financial
// effect) → cancel. Read-only: never confirms, so no subscription price is actually migrated.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('tariff migration wizard opens, selects, previews with no financial effect', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/plans/pro', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  await page.locator('[data-test="open-migration-wizard"]').click()
  await expect(page.locator('[data-test="tariff-migration-wizard"]')).toBeVisible({ timeout: 8000 })
  await expect(page.locator('[data-test="wiz-step-select"]')).toBeVisible()

  // Select the first subscription if any exist on this plan.
  const firstCheckbox = page.locator('[data-test="wiz-step-select"] tbody tr input[type="checkbox"]').first()
  const hasSubs = await firstCheckbox.count()
  test.skip(hasSubs === 0, 'no active subscriptions on plan pro in this environment')

  await firstCheckbox.check()
  await page.locator('[data-test="wiz-next-1"]').click()
  await expect(page.locator('[data-test="wiz-step-price"]')).toBeVisible()

  await page.locator('[data-test="wiz-new-price"] input').fill('39')
  await page.locator('[data-test="wiz-next-2"]').click()
  await expect(page.locator('[data-test="wiz-step-preview"]')).toBeVisible({ timeout: 8000 })
  // The preview explicitly states no invoice/payment is created.
  await expect(page.locator('[data-test="wiz-step-preview"]')).toContainText(/aucune facture|no invoice/i)

  // Cancel — do not apply (read-only verification).
  await page.getByRole('button', { name: /Annuler|Cancel/i }).click()
  await expect(page.locator('[data-test="tariff-migration-wizard"]')).toBeHidden()
})
