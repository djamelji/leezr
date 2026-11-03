// @ts-check
// ADR-690 (WORKFORCE EXPERIENCE 2026 / E2) — the company sidebar organises Workforce around a single
// activity group « Ressources humaines », whose entries follow the activity order (Collaborateurs,
// Temps de travail, Congés & absences, Documents, Paie & déclarations, Organisation, Paramètres).
// Entries route to their real workforce pages and render in dark mode without horizontal overflow.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('Workforce activity nav renders a single group and routes correctly', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce', { waitUntil: 'networkidle' })

  const nav = page.locator('.layout-vertical-nav')

  // The single activity group heading « Ressources humaines » is present.
  await expect(nav.getByText('Ressources humaines', { exact: false }).first(), 'group heading').toBeVisible({ timeout: 10000 })

  // Owner (sees all) → every activity entry is present in the sidebar.
  for (const label of ['Collaborateurs', 'Temps de travail', 'Congés & absences', 'Documents', 'Paie & déclarations', 'Organisation', 'Paramètres']) {
    await expect(nav.getByText(label, { exact: false }).first(), `entry "${label}"`).toBeVisible({ timeout: 10000 })
  }

  // A Workforce entry routes to its real workforce page.
  const employeesLink = nav.locator('a[href*="/company/workforce/employees"]').first()
  await expect(employeesLink).toBeVisible()
  await employeesLink.click()
  await expect(page).toHaveURL(/\/company\/workforce\/employees/, { timeout: 8000 })

  await page.screenshot({ path: `${SCRATCH}/e2-nav-light.png`, fullPage: false })

  // Dark mode: group + entries still render, no horizontal overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await expect(nav.getByText('Ressources humaines', { exact: false }).first()).toBeVisible()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()
  await page.screenshot({ path: `${SCRATCH}/e2-nav-dark.png`, fullPage: false })

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
