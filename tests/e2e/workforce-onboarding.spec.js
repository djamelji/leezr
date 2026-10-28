// @ts-check
// ADR-637 (W-F) — the HR onboarding assistant renders as a business-term stepper, reuses W-E for the
// organization step, and can be completed (server-side resumable state). Tests are state-independent.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('HR onboarding wizard renders in business terms and reuses W-E for the org step', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')

  // Entry point from the Organization page.
  await page.goto('/company/workforce/organization/departments', { waitUntil: 'networkidle' })
  await expect(page.getByRole('link', { name: /Assistant de configuration/ })).toBeVisible({ timeout: 10000 })

  await page.goto('/company/workforce/onboarding', { waitUntil: 'networkidle' })
  await expect(page.getByText('Assistant de configuration RH')).toBeVisible({ timeout: 10000 })
  await expect(page.getByText('Votre entreprise').first()).toBeVisible()
  await expect(page.getByText('Votre organisation').first()).toBeVisible()
  await page.screenshot({ path: `${SCRATCH}/wf-onboarding.png`, fullPage: false })

  // Jump to the organization step via its (clickable) progress chip — state-independent.
  await page.getByText('Votre organisation').first().click()
  await expect(page.getByText('Organisation proposée')).toBeVisible({ timeout: 8000 })

  // Dark mode + no overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('HR onboarding can be completed end-to-end (resumable finish)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/onboarding', { waitUntil: 'networkidle' })

  // Walk every step from wherever the resumed state is: Continue, else Later, until "done".
  for (let i = 0; i < 12; i++) {
    if (await page.getByText('Votre espace RH est prêt').isVisible().catch(() => false)) break
    const cont = page.getByRole('button', { name: /^Continuer$/ })
    const later = page.getByRole('button', { name: /Je le ferai plus tard/ })
    if (await cont.isVisible().catch(() => false)) await cont.click()
    else if (await later.isVisible().catch(() => false)) await later.click()
    else break
    await page.waitForTimeout(400)
  }

  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await expect(page.getByText('Votre espace RH est prêt')).toBeVisible({ timeout: 10000 })
  // Finish succeeds (confirmation toast) — server-side 'completed' state is covered by PHPUnit.
  await page.getByRole('button', { name: /Terminer/ }).click()
  await expect(page.getByText(/Configuration terminée/)).toBeVisible({ timeout: 8000 })
  await expect(page.getByText('Votre espace RH est prêt')).toBeVisible()
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
