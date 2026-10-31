// @ts-check
// ADR-635 → W-HR-UX-7 — the company sidebar groups Workforce entries by TASK (visible section
// headings, manifest-driven): "Collaborateurs", "Temps & planning", "Absences", "Documents RH",
// "Paramètres RH". Entries route correctly and render in dark mode without overflow.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('HR nav task groups render with workforce entries and route correctly', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce', { waitUntil: 'networkidle' })

  const nav = page.locator('.layout-vertical-nav')

  // Task-based section headings are present (no more single "Ressources humaines" wall).
  for (const heading of ['Collaborateurs', 'Temps & planning', 'Absences']) {
    await expect(nav.getByText(heading, { exact: false }).first(), `heading "${heading}"`).toBeVisible({ timeout: 10000 })
  }

  // A Workforce entry lives in the sidebar and routes to its workforce page.
  const employeesLink = nav.locator('a[href*="/company/workforce/employees"]').first()
  await expect(employeesLink).toBeVisible()
  await employeesLink.click()
  await expect(page).toHaveURL(/\/company\/workforce\/employees/, { timeout: 8000 })

  await page.screenshot({ path: `${SCRATCH}/hr-nav.png`, fullPage: false })

  // Dark mode: headings still render, no horizontal overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await expect(nav.getByText('Collaborateurs', { exact: false }).first()).toBeVisible()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
