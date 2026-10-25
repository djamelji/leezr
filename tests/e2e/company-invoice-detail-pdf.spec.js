// @ts-check
// ADR-625 (LOT 6g-B) — real invoice-detail + PDF-download journey (not just render). Opens an
// invoice from the list, checks the detail renders amounts, then actually downloads the PDF and
// verifies the file is a real PDF (magic bytes), no JS error.
import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

test('owner can open an invoice detail and download a real PDF', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/invoices', { waitUntil: 'networkidle' })

  const firstNumber = page.locator('.v-data-table a[href*="/company/billing/invoices/"]').first()
  await expect(firstNumber).toBeVisible({ timeout: 10000 })

  // Open the detail.
  await firstNumber.click()
  await expect(page).toHaveURL(/\/company\/billing\/invoices\/\d+/, { timeout: 8000 })
  // The detail renders monetary amounts (formatMoney → "… €").
  await expect(page.getByText(/€/).first()).toBeVisible({ timeout: 10000 })

  // Back to the list and download the PDF for real (PDF gen can be slow on the VPS → generous wait).
  await page.goto('/company/billing/invoices', { waitUntil: 'networkidle' })
  const downloadBtn = page.locator('.v-data-table button:has(.tabler-download)').first()
  await expect(downloadBtn).toBeVisible({ timeout: 10000 })
  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 45000 }),
    downloadBtn.click(),
  ])

  expect(download.suggestedFilename()).toMatch(/\.pdf$/)
  const path = await download.path()
  const head = readFileSync(path).subarray(0, 5).toString('latin1')
  expect(head, 'downloaded file must be a real PDF').toBe('%PDF-')
  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
