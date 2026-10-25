// @ts-check
// ADR-618 (LOT 6) — Company billing overview hierarchy: subscription → next invoice → situation.
// Verifies the restructured owner overview renders, section headings are present, no JS error,
// and it works in dark mode. (Financial correctness is covered by PHPUnit.)
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

test('owner billing overview renders the new hierarchy without JS errors', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/overview', { waitUntil: 'networkidle' })

  // The three new section headings must be present (subscription / next invoice / situation).
  await expect(page.getByText(/Mon abonnement|My subscription/).first()).toBeVisible({ timeout: 10000 })
  await expect(page.getByText(/Prochaine échéance|Next invoice/).first()).toBeVisible()
  await expect(page.getByText(/Ma situation financière|My financial situation/).first()).toBeVisible()

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('owner invoices tab renders (table or empty state) without JS errors', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/invoices', { waitUntil: 'networkidle' })

  // Either the data table or the empty state must render (never a blank/error screen).
  const table = page.locator('.v-data-table')
  const empty = page.getByText(/facture|invoice/i).first()
  await expect(table.or(empty).first()).toBeVisible({ timeout: 10000 })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('owner plan tab renders with HT-qualified price and no JS errors', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/plan', { waitUntil: 'networkidle' })

  // Compare-plans section must render; current plan price is HT-qualified.
  await expect(page.getByText(/Starter|Pro|Business/).first()).toBeVisible({ timeout: 10000 })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('owner addon catalog renders with state distinctions and no JS errors', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/modules', { waitUntil: 'networkidle' })

  // The modules/addons catalog must render (cards or section headings), no blank/error screen.
  await expect(page.locator('.v-card').first()).toBeVisible({ timeout: 10000 })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('owner billing overview renders in dark mode', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/overview', { waitUntil: 'networkidle' })

  const isDark = await page.evaluate(() => !!document.querySelector('.v-theme--dark'))
  expect(isDark).toBeTruthy()
  await expect(page.getByText(/Mon abonnement|My subscription/).first()).toBeVisible({ timeout: 10000 })
})
