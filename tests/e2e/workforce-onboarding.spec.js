// @ts-check
// ADR-637 (W-F) — the HR onboarding assistant renders as a business-term stepper, is resumable,
// and reuses W-E for the organization step. Read-only browser check (no finish/mutation).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('HR onboarding wizard renders and advances in business terms', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')

  // Entry point from the Organization page.
  await page.goto('/company/workforce/organization/departments', { waitUntil: 'networkidle' })
  await expect(page.getByRole('link', { name: /Assistant de configuration/ })).toBeVisible({ timeout: 10000 })

  await page.goto('/company/workforce/onboarding', { waitUntil: 'networkidle' })
  await expect(page.getByText('Assistant de configuration RH')).toBeVisible({ timeout: 10000 })
  // Step chips in business terms.
  await expect(page.getByText('Votre entreprise').first()).toBeVisible()
  await expect(page.getByText('Votre organisation').first()).toBeVisible()
  await page.screenshot({ path: `${SCRATCH}/wf-onboarding.png`, fullPage: false })

  // Advance from "Votre entreprise".
  await page.getByRole('button', { name: /Continuer/ }).click()
  // Organization step embeds the W-E preset card.
  await expect(page.getByText('Organisation proposée')).toBeVisible({ timeout: 8000 })

  // Dark mode + no overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
