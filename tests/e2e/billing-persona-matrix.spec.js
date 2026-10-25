// @ts-check
// ADR-631j (LOT 8) — multi-persona billing matrix. Adds the coverage dimensions that were previously
// render-only: responsive (mobile), dark mode, cross-persona (platform admin + company owner), and
// REAL interaction on the screens changed this cycle (dead-letter inspect ADR-631g, blocked-renewal
// flag ADR-631b). Interactions are CONDITIONAL on data and log explicitly when data is absent — never
// a silent skip. No real financial action is triggered.
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'

test.describe('LOT 8 — billing persona matrix', () => {
  test('platform cockpit: responsive (mobile) + dark mode, blocked-renewals KPI present', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.setViewportSize({ width: 390, height: 844 }) // mobile
    await loginPlatform(page, 'admin@leezr.com')
    // domcontentloaded (not networkidle): the cockpit's apexchart + polling keeps the network
    // busy at mobile width, so we wait on a concrete element instead.
    await page.goto('/platform/billing/overview', { waitUntil: 'domcontentloaded' })
    await expect(page.locator('.v-card, .widget-root').first()).toBeVisible({ timeout: 15000 })

    const isDark = await page.evaluate(() => !!document.querySelector('.v-theme--dark'))
    expect(isDark, 'dark theme applied').toBeTruthy()
    // Body must not overflow horizontally on mobile.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow, 'no horizontal page overflow on mobile').toBeLessThanOrEqual(2)
    // ADR-631 blocked-renewals KPI card present (content, not just render).
    await expect(page.locator('[class*="lock-exclamation"]').first()).toBeVisible({ timeout: 10000 })
    expect(errors, errors.join(' | ')).toHaveLength(0)
  })

  test('platform recovery: dead-letter inspection dialog (ADR-631g)', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))
    await loginPlatform(page, 'admin@leezr.com')
    await page.goto('/platform/billing/recovery', { waitUntil: 'networkidle' })
    await expect(page.locator('.v-tab, .v-card').first()).toBeVisible({ timeout: 10000 })

    const inspect = page.locator('[data-test="dl-inspect"]').first()
    if (await inspect.count() > 0) {
      await inspect.click()
      // The payload (previously SQL-only) is now visible in the UI.
      await expect(page.locator('[data-test="dl-detail-payload"]')).toBeVisible({ timeout: 8000 })
      await expect(page.locator('[data-test="dl-detail-error"]')).toBeVisible()
    } else {
      // eslint-disable-next-line no-console
      console.log('[matrix] no dead-letter present on this env — inspection interaction not exercised')
    }
    expect(errors, errors.join(' | ')).toHaveLength(0)
  })

  test('platform subscriptions: blocked-renewal flag when a provisional price exists (ADR-631b)', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))
    await loginPlatform(page, 'admin@leezr.com')
    await page.goto('/platform/billing/subscriptions', { waitUntil: 'networkidle' })
    await expect(page.locator('.v-data-table, .v-card').first()).toBeVisible({ timeout: 10000 })

    const flag = page.locator('[data-test="sub-renewal-blocked"]')
    if (await flag.count() > 0) {
      await expect(flag.first()).toBeVisible()
    } else {
      // eslint-disable-next-line no-console
      console.log('[matrix] no blocked-renewal subscription on this env — flag not exercised')
    }
    expect(errors, errors.join(' | ')).toHaveLength(0)
  })

  test('company owner: billing overview responsive + dark (persona = owner)', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.setViewportSize({ width: 390, height: 844 })
    await loginCompany(page, 'owner@leezr.test')
    await page.goto('/company/billing/overview', { waitUntil: 'networkidle' })

    const isDark = await page.evaluate(() => !!document.querySelector('.v-theme--dark'))
    expect(isDark).toBeTruthy()
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow, 'no horizontal overflow on mobile').toBeLessThanOrEqual(2)
    await expect(page.locator('.v-card').first()).toBeVisible({ timeout: 10000 })
    expect(errors, errors.join(' | ')).toHaveLength(0)
  })

  test('company owner: internal provider does not offer self-service online payment (ADR-623)', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))
    await loginCompany(page, 'owner@leezr.test')
    await page.goto('/company/billing/pay', { waitUntil: 'networkidle' })
    await expect(page.locator('.v-card, .v-alert, .v-empty-state').first()).toBeVisible({ timeout: 10000 })
    // The internal provider must never present an enabled "pay online" CTA (financial-capability truth).
    const payNow = page.getByRole('button', { name: /payer en ligne|pay online/i })
    expect(await payNow.count(), 'no online-pay CTA for internal provider').toBe(0)
    expect(errors, errors.join(' | ')).toHaveLength(0)
  })
})
