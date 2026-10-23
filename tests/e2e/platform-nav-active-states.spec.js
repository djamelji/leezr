// @ts-check
// ADR-598 (Phase 2 LOT G) — active-state & UX coherence for hub children.
// The sidebar cannot exact-highlight children that share one route name (@layouts matches by
// route name only, and @layouts is immutable per project law). We therefore guarantee a clear
// IN-PAGE active tab (Vuetify VTabs) and correct behaviour on navigation / reload / back.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

// Assert the specific tab (identified by its label) carries Vuetify's active class.
const expectTabActive = (page, re) =>
  expect(page.locator('.v-tab', { hasText: re }).first()).toHaveClass(/v-tab--selected|v-slide-group-item--active/, { timeout: 8000 })

test('navigating between two children of the same hub updates the in-page active tab', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')

  await page.goto('/platform/catalog/plans', { waitUntil: 'networkidle' })
  await expectTabActive(page, /Plans/i)

  await page.goto('/platform/catalog/jobdomains', { waitUntil: 'networkidle' })
  await expectTabActive(page, /Domaines|Job ?domains/i)
})

test('direct reload on a sub-tab keeps that tab active in the page', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/operations/ai', { waitUntil: 'networkidle' })
  await expectTabActive(page, /AI|IA/i)

  await page.reload({ waitUntil: 'networkidle' })
  await expectTabActive(page, /AI|IA/i)
})

test('browser back restores the previous sub-tab', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/catalog/modules', { waitUntil: 'networkidle' })
  await expectTabActive(page, /Modules/i)
  await page.goto('/platform/catalog/plans', { waitUntil: 'networkidle' })
  await expectTabActive(page, /Plans/i)

  await page.goBack({ waitUntil: 'networkidle' })
  await expectTabActive(page, /Modules/i)
})

test.describe('dark mode', () => {
  test.use({ colorScheme: 'dark' })
  test('platform nav renders without JS errors in dark color scheme', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))
    await loginPlatform(page, 'admin@leezr.com')
    await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
    await expect(page.locator('.layout-vertical-nav')).toBeVisible({ timeout: 8000 })
    expect(errors, `no JS errors: ${errors.join(' | ')}`).toHaveLength(0)
  })
})
