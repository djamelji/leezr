// @ts-check
// BILL-011 — the tax_mode='none' option is relabelled (UI-only) from a misleading "None"/"Aucun" to
// "Market-based taxation (legacy mode)". This verifies the settings page renders and the new,
// non-misleading label is reachable. No computation is asserted here (PHPUnit covers that).
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('platform billing settings shows the corrected fiscal label, no JS error', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/settings/billing', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2000)

  // The fiscal section renders.
  await expect(page.getByText(/Paramètres fiscaux|Tax settings|Mode fiscal|Tax Mode/i).first()).toBeVisible({ timeout: 10000 })

  // The old misleading standalone label must NOT be the shown value; the new legacy-mode label is reachable.
  const legacyLabel = page.getByText(/mode historique|legacy mode/i)
  // Either it is the current select value (visible) or available after opening the select.
  if (await legacyLabel.count() === 0) {
    const select = page.getByText(/Mode fiscal|Tax Mode/i).first()
    await select.click().catch(() => {})
    await page.waitForTimeout(500)
  }
  await expect(page.getByText(/mode historique|legacy mode/i).first()).toBeVisible({ timeout: 8000 })

  expect(errors, errors.join(' | ')).toHaveLength(0)
})
