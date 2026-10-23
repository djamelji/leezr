// @ts-check
// ADR-601 — consolidated "Usage & Consumption" page (Operations hub, tab=usage).
// Real UI → API: section-level permission gating, no forbidden API call for hidden sections,
// AI usage sub-tab removed (contextual link instead), old URL preserved.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

const page403ToUsage = (page, bucket) => page.on('response', r => { if (r.status() === 403) bucket.push(r.url().replace(/^https?:\/\/[^/]+/, '')) })

test('super admin: all three sections render with no JS errors', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/operations/usage', { waitUntil: 'networkidle' })
  await expect(page.getByText(/Utilisation & Consommation|Usage & Consumption/i).first()).toBeVisible({ timeout: 8000 })
  await expect(page.locator('[data-test="usage-section-overview"]')).toBeVisible()
  await expect(page.locator('[data-test="usage-section-company"]')).toBeVisible()
  await expect(page.locator('[data-test="usage-section-ai"]')).toBeVisible()
  await page.waitForTimeout(1500)
  expect(errors, `no JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('view_usage_monitoring only: resource sections, NO AI section, no forbidden AI call', async ({ page }) => {
  const forbidden = []
  page403ToUsage(page, forbidden)
  await loginPlatform(page, 'usage-only@leezr.com')
  await page.goto('/platform/operations/usage', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="usage-section-overview"]')).toBeVisible({ timeout: 8000 })
  await expect(page.locator('[data-test="usage-section-ai"]')).toHaveCount(0)
  await page.waitForTimeout(1500)
  // The AI section is hidden → no /ai/usage call was made (so no 403 either).
  expect(forbidden.filter(u => u.includes('/ai/usage')), 'no AI call for a usage-only user').toHaveLength(0)
})

test('view_ai only: AI section only, NO resource overview, no forbidden usage call', async ({ page }) => {
  const forbidden = []
  page403ToUsage(page, forbidden)
  await loginPlatform(page, 'ai-only@leezr.com')
  await page.goto('/platform/operations/usage', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="usage-section-ai"]')).toBeVisible({ timeout: 8000 })
  await expect(page.locator('[data-test="usage-section-overview"]')).toHaveCount(0)
  await page.waitForTimeout(1500)
  expect(forbidden.filter(u => u.includes('/usage/overview') || u.includes('/usage/company')), 'no resource-usage call for an AI-only user').toHaveLength(0)
})

test('AI tab no longer has a Usage sub-tab; contextual link present', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/operations/ai', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="ai-usage-link"]')).toBeVisible({ timeout: 8000 })
  // No AI sub-tab titled "Usage/Utilisation" anymore.
  await expect(page.locator('.v-tab', { hasText: /^Usage$|^Utilisation$/i })).toHaveCount(0)
})

test('old URL preserved + dark mode renders', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/operations/usage', { waitUntil: 'networkidle' })
  await page.reload({ waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="usage-section-overview"]')).toBeVisible({ timeout: 8000 })
})
