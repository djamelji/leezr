// @ts-check
// ADR-690 (WORKFORCE EXPERIENCE 2026 / E2) — the company sidebar organises Workforce around a single
// activity group « Ressources humaines », whose entries follow the activity order (Collaborateurs,
// Temps de travail, Congés & absences, Documents, Paie & déclarations, Organisation, Paramètres).
// Entries route to their real workforce pages and render in dark mode without horizontal overflow.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('Workforce activity nav renders a single group and routes correctly', async ({ page }) => {
  // ADR-766/781/802 : il n'y a plus de groupe « Ressources humaines » — la navigation Company est organisée en
  // espaces ; les personnes vivent dans « Équipe » (/company/people). On teste la navigation réelle.
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  const nav = page.locator('.layout-vertical-nav')
  await expect(nav).toBeVisible({ timeout: 15000 })
  for (const href of ['/company/people', '/company/documents', '/company/workforce/payroll']) {
    await expect(nav.locator(`a[href^="${href}"]`).first(), `entry ${href}`).toBeAttached({ timeout: 10000 })
  }
  await expect(nav.getByText('Ressources humaines', { exact: true })).toHaveCount(0) // ancien groupe retiré

  const team = nav.locator('a[href^="/company/people"]').first()
  await team.scrollIntoViewIfNeeded()
  await team.click()
  await expect(page).toHaveURL(/\/company\/people/, { timeout: 8000 })

  // Dark mode : pas de débordement horizontal.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
