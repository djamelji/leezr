// @ts-check
// ADR-598 (Phase 2) — modular, permission-driven platform navigation rendered by Vuexy.
// Real UI → API → navigation: the sidebar is built from manifests + NavGroupRegistry + the
// user's effective permissions. Runs against the local build (leezr.test).
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

const nav = page => page.locator('.layout-vertical-nav')

test('super admin sees the full 6-domain taxonomy with a collapsible Governance group', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await expect(nav(page)).toBeVisible({ timeout: 8000 })

  // The six canonical group headings are present (bilingual-tolerant).
  for (const re of [/Cockpit/i, /Clients/i, /Finance/i, /Governance|Gouvernance/i, /Configuration/i, /System|Système/i]) {
    await expect(nav(page).locator('.nav-section-title', { hasText: re }).first()).toBeVisible()
  }

  // Governance is a collapsible group; expanding reveals its children (Catalogue → Plans/JobDomains).
  const governanceGroup = nav(page).locator('.nav-group', { hasText: /Catalog|Catalogue/i }).first()
  await governanceGroup.locator('.nav-group-label, .nav-link').first().click()
  await expect(nav(page).getByText(/Plans/i).first()).toBeVisible({ timeout: 5000 })
})

test('a restricted Finance Reader sees only its authorized groups', async ({ page }) => {
  await loginPlatform(page, 'finance-reader@leezr.com')
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await expect(nav(page)).toBeVisible({ timeout: 8000 })

  // Authorized: Cockpit (dashboard) + Finance (billing).
  await expect(nav(page).locator('.nav-section-title', { hasText: /Finance/i }).first()).toBeVisible()
  await expect(nav(page).getByText(/Billing|Facturation/i).first()).toBeVisible()

  // NOT authorized → groups absent (no link to unavailable features).
  await expect(nav(page).locator('.nav-section-title', { hasText: /Governance|Gouvernance/i })).toHaveCount(0)
  await expect(nav(page).locator('.nav-section-title', { hasText: /System|Système/i })).toHaveCount(0)
  await expect(nav(page).locator('.nav-section-title', { hasText: /Clients/i })).toHaveCount(0)
})

test('navigation survives a full page reload (active state preserved)', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/overview', { waitUntil: 'networkidle' })
  await expect(nav(page)).toBeVisible({ timeout: 8000 })
  await page.reload({ waitUntil: 'networkidle' })
  // Billing link is marked active after reload.
  await expect(nav(page).locator('.router-link-active, .router-link-exact-active').filter({ hasText: /Billing|Facturation/i }).first()).toBeVisible({ timeout: 8000 })
})

test('mobile viewport exposes the nav via the hamburger toggle', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await page.locator('#vertical-nav-toggle-btn').click()
  await expect(nav(page).locator('.nav-section-title', { hasText: /Cockpit/i }).first()).toBeVisible({ timeout: 8000 })
})
