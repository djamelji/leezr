// @ts-check
// ADR-630 (LOT 7.3-7.5) — every Platform Billing tab renders for an authorized admin with no JS
// error. One login, sequential navigation (avoids the login throttle). Covers the full admin
// financial surface: invoices, payments, dunning, scheduled-debits, credit-notes, coupons, wallets,
// forensics, governance, ledger, recovery, reconciliation.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

const TABS = [
  'invoices', 'payments', 'dunning', 'scheduled-debits', 'credit-notes', 'coupons',
  'wallets', 'forensics', 'governance', 'ledger', 'recovery', 'reconciliation',
]

test('every platform billing tab renders without JS errors', async ({ page }) => {
  test.setTimeout(120000)
  const errorsByTab = {}
  page.on('pageerror', e => {
    const tab = page.url().split('/billing/')[1]?.split(/[?#]/)[0] || 'unknown'
    ;(errorsByTab[tab] ??= []).push(e.message)
  })

  await loginPlatform(page, 'admin@leezr.com')

  for (const tab of TABS) {
    await page.goto(`/platform/billing/${tab}`, { waitUntil: 'networkidle' })
    // The billing tab shell is present on every tab (no blank/crash screen).
    await expect(page.locator('.v-tab').first(), `tab ${tab} must render the billing shell`).toBeVisible({ timeout: 12000 })
    // And some content area rendered (table / card / empty-state).
    await expect(
      page.locator('.v-data-table, .v-card, .v-table, .text-center, .widget-root').first(),
      `tab ${tab} must render content`,
    ).toBeVisible({ timeout: 12000 })
  }

  const failing = Object.entries(errorsByTab).filter(([, v]) => v.length)
  expect(failing.map(([t, v]) => `${t}: ${v.join(', ')}`).join(' | '), 'JS errors per tab').toHaveLength(0)
})
