// @ts-check
// Platform navigation = ONE link per hub (no internal hub tab injected into the sidebar, no
// multiple active highlight). Restricted roles see only the hubs they can access. Runs against
// the deployed build.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

const nav = page => page.locator('.layout-vertical-nav')

test('super admin sees exactly one link per hub across the six groups', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await expect(nav(page)).toBeVisible({ timeout: 8000 })

  for (const re of [/Cockpit/i, /Clients/i, /Finance/i, /Governance|Gouvernance/i, /Configuration/i, /System|Système/i]) {
    await expect(nav(page).locator('.nav-section-title', { hasText: re }).first()).toBeVisible()
  }

  // Hub links present…
  for (const re of [/Dashboard|Tableau/i, /Billing|Facturation/i, /Catalog|Catalogue/i, /Operations|Opérations/i]) {
    await expect(nav(page).getByRole('link', { name: re }).first()).toBeVisible()
  }

  // …and NO internal hub tab injected as a sidebar link (the regression we rolled back).
  for (const re of [/^Plans/i, /Job ?domains|Domaines/i, /^Markets/i, /^Invoices$|^Factures$/i, /^Payments$|^Paiements$/i, /Reconciliation|Rapprochement/i]) {
    await expect(nav(page).getByRole('link', { name: re })).toHaveCount(0)
  }
})

test('exactly one sidebar link is active on a hub page (no multiple highlight)', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/invoices', { waitUntil: 'networkidle' })
  await expect(nav(page)).toBeVisible({ timeout: 8000 })
  // Only the single Billing hub link is active.
  await expect(nav(page).locator('a.router-link-active')).toHaveCount(1)
  await expect(nav(page).locator('a.router-link-active')).toContainText(/Billing|Facturation/i)
})

test('a restricted Finance Reader sees only the Finance hub', async ({ page }) => {
  await loginPlatform(page, 'finance-reader@leezr.com')
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await expect(nav(page)).toBeVisible({ timeout: 8000 })
  await expect(nav(page).locator('.nav-section-title', { hasText: /Finance/i }).first()).toBeVisible()
  await expect(nav(page).getByRole('link', { name: /Billing|Facturation/i }).first()).toBeVisible()
  await expect(nav(page).locator('.nav-section-title', { hasText: /Governance|Gouvernance/i })).toHaveCount(0)
  await expect(nav(page).locator('.nav-section-title', { hasText: /System|Système/i })).toHaveCount(0)
})

test('internal hub tabs still work (billing invoices tab active in page)', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/invoices', { waitUntil: 'networkidle' })
  await page.reload({ waitUntil: 'networkidle' })
  await expect(page.locator('.v-tab', { hasText: /Invoices|Factures/i }).first()).toHaveClass(/v-tab--selected|v-slide-group-item--active/, { timeout: 8000 })
})

test('mobile viewport exposes the nav via the hamburger toggle', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await page.locator('#vertical-nav-toggle-btn').click()
  await expect(nav(page).locator('.nav-section-title', { hasText: /Cockpit/i }).first()).toBeVisible({ timeout: 8000 })
})
