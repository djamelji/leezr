// @ts-check
// ADR-632 (M9) — Platform-side V2 consolidated invoice. An authorized platform user (super-admin)
// sees the same consolidated sections and amounts as the Company. Permission enforcement for limited
// platform users is proven by platform-rbac-workflow.spec.js (API-level), not duplicated here.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('authorized platform user sees the V2 consolidated invoice by sections', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/invoices', { waitUntil: 'networkidle' })

  const link = page.getByRole('link', { name: 'INV-RECETTE-V2', exact: true }).first()
  await expect(link).toBeVisible({ timeout: 10000 })
  await link.click()
  await expect(page).toHaveURL(/\/platform\/billing\/invoices\/\d+/, { timeout: 8000 })

  // Consolidated sections render on the platform detail too.
  await expect(page.getByText('Abonnement', { exact: false }).first()).toBeVisible({ timeout: 10000 })
  await expect(page.getByText('Modules', { exact: false }).first()).toBeVisible()
  await expect(page.getByRole('columnheader', { name: 'HT' }).first()).toBeVisible()
  await expect(page.getByRole('columnheader', { name: 'TTC' }).first()).toBeVisible()
  await expect(page.getByText('Prorata').first()).toBeVisible()

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('platform V2 invoice detail endpoint is permission-gated (unauthenticated refused)', async ({ page }) => {
  // Hitting the detail API without a platform session must not leak invoice data.
  await page.goto('/platform/login', { waitUntil: 'domcontentloaded' })
  const status = await page.evaluate(async () => {
    const r = await fetch('/api/platform/billing/invoices/1', { headers: { Accept: 'application/json' } })

    return r.status
  })
  expect([401, 403, 419, 302]).toContain(status)
})
