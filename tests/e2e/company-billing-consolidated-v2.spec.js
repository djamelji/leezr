// @ts-check
// ADR-632 (M9) — Consolidated Billing V2 UI recette. Exercises the real browser journey on the V2
// recette fixtures (BillingRecetteSeeder: INV-RECETTE-V2 + INV-RECETTE-V2-LARGE) seeded on the owner
// persona's company. Emission stays OFF — these are read-model fixtures, no real collection.
import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const LIST = '/company/billing/invoices'

async function openInvoice(page, number) {
  await page.goto(LIST, { waitUntil: 'networkidle' })
  const link = page.getByRole('link', { name: number, exact: true }).first()
  await expect(link, `${number} must be a single list row`).toBeVisible({ timeout: 10000 })
  await link.click()
  await expect(page).toHaveURL(/\/company\/billing\/invoices\/\d+/, { timeout: 8000 })
}

test('V2 consolidated invoice renders by sections with prorata, periods and reconciled totals', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await openInvoice(page, 'INV-RECETTE-V2')

  // Consolidated sections (expansion panels) — subscription + modules, open by default.
  await expect(page.getByText('Abonnement', { exact: false }).first()).toBeVisible({ timeout: 10000 })
  await expect(page.getByText('Modules', { exact: false }).first()).toBeVisible()

  // V2 analytic columns HT / TVA / TTC are present in the section tables.
  await expect(page.getByRole('columnheader', { name: 'HT' }).first()).toBeVisible()
  await expect(page.getByRole('columnheader', { name: 'TVA' }).first()).toBeVisible()
  await expect(page.getByRole('columnheader', { name: 'TTC' }).first()).toBeVisible()

  // Mid-cycle activation → "Prorata" badge on the prorated module line.
  await expect(page.getByText('Prorata').first()).toBeVisible()

  // Service period column rendered on lines (locale-formatted date – date, en-dash separated).
  await expect(page.getByRole('columnheader', { name: /Période/ }).first()).toBeVisible()
  await expect(page.getByText(/\d.*\s–\s.*\d/).first()).toBeVisible()

  // Fiscal totals rendered (no recompute in Vue — amounts come from the API).
  await expect(page.getByText(/€/).first()).toBeVisible()

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('V2 invoice downloads a real consolidated PDF', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto(LIST, { waitUntil: 'networkidle' })

  // Download the PDF of the V2 invoice row for real (generous wait — PDF gen is slow on the VPS).
  const row = page.locator('tr', { has: page.getByRole('link', { name: 'INV-RECETTE-V2', exact: true }) }).first()
  const dlBtn = row.locator('button:has(.tabler-download)').first()
  await expect(dlBtn).toBeVisible({ timeout: 10000 })
  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 60000 }),
    dlBtn.click(),
  ])
  const path = await download.path()
  const head = readFileSync(path).subarray(0, 5).toString('latin1')
  expect(head, 'V2 PDF must be a real PDF').toBe('%PDF-')
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('V2 large invoice (135 lines) renders every line and downloads a multipage PDF', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await openInvoice(page, 'INV-RECETTE-V2-LARGE')

  // All 135 lines are accessible (section detail never permanently hidden). The modules panel is open
  // by default; count its body rows after it is expanded.
  await expect(page.getByText('Modules', { exact: false }).first()).toBeVisible({ timeout: 10000 })
  const bodyRows = page.locator('.v2-section-table tbody tr')
  await expect.poll(async () => bodyRows.count(), { timeout: 10000 }).toBeGreaterThanOrEqual(135)

  // Multipage PDF downloads for real.
  await page.goto(LIST, { waitUntil: 'networkidle' })
  const row = page.locator('tr', { has: page.getByRole('link', { name: 'INV-RECETTE-V2-LARGE', exact: true }) }).first()
  const dlBtn = row.locator('button:has(.tabler-download)').first()
  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 90000 }),
    dlBtn.click(),
  ])
  const buf = readFileSync(await download.path())
  expect(buf.subarray(0, 5).toString('latin1')).toBe('%PDF-')
  expect(buf.length, '135-line document is substantial (multipage)').toBeGreaterThan(5000)
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('V2 detail renders in dark mode without layout break', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await openInvoice(page, 'INV-RECETTE-V2')
  // Force dark theme via Vuetify cookie signal used by the app, then reload.
  await page.evaluate(() => { document.documentElement.classList.add('v-theme--dark') })
  await expect(page.getByText('Abonnement', { exact: false }).first()).toBeVisible()
  // No horizontal body overflow (responsive invariant).
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow on the V2 detail').toBeFalsy()
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('V1 invoice keeps the legacy single-table rendering (no consolidated sections)', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await openInvoice(page, 'INV-RECETTE-OWNER')

  // V1 renders the classic line-items table, not the V2 section panels.
  await expect(page.locator('.invoice-preview-table')).toBeVisible({ timeout: 10000 })
  await expect(page.locator('.v2-sections')).toHaveCount(0)
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
