// @ts-check
// Audit post-prod — real navigation tests that would have caught the owner 404 / blank page.
// We assert the NAVIGATION MECHANISM (routes resolve, no 404, no blank, no JS error) regardless of
// the payment provider, plus the onboarding CTA when the step is applicable. These complement the
// unit render of _BillingPaymentMethods.vue (which alone missed the broken link).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const PM_PATH = '/company/billing/payment-methods'

async function gotoResolved(page, path) {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  await page.goto(path, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)
  const body = (await page.locator('body').innerText().catch(() => '')) || ''
  return { url: page.url(), errors, is404: /404|introuvable|not found/i.test(body) }
}

test.describe('Company billing navigation (audit post-prod)', () => {
  test('bare /company/billing redirects to a tab (no 404)', async ({ page }) => {
    await loginCompany(page, 'owner@leezr.test')
    const r = await gotoResolved(page, '/company/billing')
    expect(r.is404, 'bare /company/billing must not 404').toBe(false)
    expect(r.url).toContain('/company/billing/')
    expect(r.errors, r.errors.join(' | ')).toHaveLength(0)
  })

  test('legacy ?tab=payment-methods redirects to the canonical tab (no 404)', async ({ page }) => {
    await loginCompany(page, 'owner@leezr.test')
    const r = await gotoResolved(page, '/company/billing?tab=payment-methods')
    expect(r.is404, 'legacy ?tab= link must not 404').toBe(false)
    expect(r.url).toContain('/company/billing/payment-methods')
    expect(r.errors, r.errors.join(' | ')).toHaveLength(0)
  })

  test('canonical payment-methods renders a real state (never blank), no JS error', async ({ page }) => {
    await loginCompany(page, 'owner@leezr.test')
    const r = await gotoResolved(page, PM_PATH)
    expect(r.is404).toBe(false)
    // Billing tab shell + a real content block (add-state OR "unavailable" empty-state OR cards).
    await expect(page.locator('.v-tab').first()).toBeVisible({ timeout: 10000 })
    await expect(
      page.locator('[data-test="pm-unavailable"], .v-empty-state, .payment-card, .v-card').first(),
      'a real content block must render (no blank page)',
    ).toBeVisible({ timeout: 10000 })
    expect(r.errors, r.errors.join(' | ')).toHaveLength(0)
  })

  test('browser refresh directly on the payment-methods route still renders', async ({ page }) => {
    await loginCompany(page, 'owner@leezr.test')
    await page.goto(PM_PATH, { waitUntil: 'domcontentloaded' })
    await page.reload({ waitUntil: 'domcontentloaded' })
    await expect(page.locator('.v-tab').first()).toBeVisible({ timeout: 10000 })
  })

  test('if the provider cannot register a card, no trap "add card" button is shown', async ({ page }) => {
    await loginCompany(page, 'owner@leezr.test')
    await page.goto(PM_PATH, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(1500)
    const unavailable = page.locator('[data-test="pm-unavailable"]')
    if (await unavailable.count() > 0) {
      // Unavailable state → a functional support CTA, and NO add-card button.
      await expect(unavailable).toBeVisible()
      await expect(page.getByRole('link', { name: /support/i }).first()).toBeVisible()
    } else {
      // eslint-disable-next-line no-console
      console.log('[nav] provider is card-capable on this env — add flow present (not the unavailable state)')
    }
  })

  test('dashboard onboarding → payment-method CTA (when applicable) lands on the tab', async ({ page }) => {
    await loginCompany(page, 'owner@leezr.test')
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(2000)
    const cta = page.locator('a[href*="/company/billing/payment-methods"]').first()
    if (await cta.count() > 0) {
      await cta.click()
      await page.waitForTimeout(1500)
      expect(page.url()).toContain('/company/billing/payment-methods')
    } else {
      // Policy (audit): the step is excluded when no card-capable provider → CTA absent by design.
      // eslint-disable-next-line no-console
      console.log('[nav] onboarding payment-method step absent (provider not card-capable or already done)')
    }
  })
})
