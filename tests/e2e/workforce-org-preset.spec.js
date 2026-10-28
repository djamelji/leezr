// @ts-check
// ADR-636 (W-E) — the Organization page shows a "Suggested organization" card; preview opens a
// non-destructive dialog listing proposed departments/roles. Read-only browser check (no apply).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('org preset card renders and preview is non-destructive', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/organization/departments', { waitUntil: 'networkidle' })

  // The suggested-organization card is present.
  await expect(page.getByText('Organisation proposée')).toBeVisible({ timeout: 10000 })

  // Preview opens the non-destructive dialog.
  await page.getByRole('button', { name: /Prévisualiser le modèle/ }).click()
  const dlg = page.getByRole('dialog')
  await expect(dlg.getByText(/Aperçu de l'organisation proposée/)).toBeVisible({ timeout: 8000 })
  await expect(dlg.getByText(/Simulation non destructive/)).toBeVisible()
  await expect(dlg.getByText('Départements', { exact: true })).toBeVisible()
  // Proposed items are listed (e.g. the Exploitation department from the seeded preset).
  await expect(dlg.getByText('Exploitation', { exact: true }).first()).toBeVisible()

  await page.screenshot({ path: `${SCRATCH}/org-preset.png`, fullPage: false })
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
