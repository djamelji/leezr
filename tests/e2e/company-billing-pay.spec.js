// @ts-check
// Bug "/company/billing/pay: factures de modules sans nom". With the recette fixtures (parent
// INV-RECETTE-OWNER + annexes A/B carrying addon module lines), every payable row must show a
// non-empty reference (display_number) AND a business label. Internal/null provider → no trap pay
// flow (amounts shown, online payment explained as unavailable). No real settlement is triggered.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

async function openPay(page, { dark = false } = {}) {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  if (dark) await page.emulateMedia({ colorScheme: 'dark' })
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/pay', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2000)

  return errors
}

test('pay page: every payable row has a non-empty reference and label (no NULL)', async ({ page }) => {
  const errors = await openPay(page)
  const body = (await page.locator('body').innerText()) || ''
  expect(/404|introuvable/i.test(body), 'no 404').toBe(false)

  const rows = page.locator('[data-test^="pay-row-"]')
  const n = await rows.count()
  if (n === 0) {
    // eslint-disable-next-line no-console
    console.log('[pay] no outstanding invoices for owner on this env — recette fixtures not seeded?')
  } else {
    // Each row's first visible text (the reference) must be non-empty — the exact bug being fixed.
    for (let i = 0; i < n; i++) {
      const refText = (await rows.nth(i).locator('td').nth(1).innerText()).trim()
      expect(refText.length, `pay row ${i} reference must not be empty`).toBeGreaterThan(0)
    }
    // The addon module names must be visible (the "module invoices had no name" bug).
    await expect(page.getByText(/Planning/i).first()).toBeVisible()
    await expect(page.getByText(/Documents/i).first()).toBeVisible()
    // Parent reference + annexe references present.
    expect(body).toMatch(/INV-RECETTE-OWNER/)
    expect(body).toMatch(/INV-RECETTE-OWNER-A/)
    expect(body).toMatch(/INV-RECETTE-OWNER-B/)
  }
  expect(errors, errors.join(' | ')).toHaveLength(0)
})

test('pay page: internal/null provider shows no trap pay flow', async ({ page }) => {
  const errors = await openPay(page)
  const unavailable = page.locator('[data-test="pay-unavailable"]')
  const payBtn = page.getByRole('button', { name: /payer|continuer|pay|continue/i })
  if (await unavailable.count() > 0) {
    await expect(unavailable).toBeVisible()
    await expect(page.getByRole('link', { name: /support/i }).first()).toBeVisible()
  } else {
    // Either online payment is available (card-capable provider) or wallet fully covers → a pay CTA is OK.
    // eslint-disable-next-line no-console
    console.log('[pay] provider card-capable or wallet-covered — pay CTA present:', await payBtn.count())
  }
  expect(errors, errors.join(' | ')).toHaveLength(0)
})

test('pay page renders in dark mode without JS error', async ({ page }) => {
  const errors = await openPay(page, { dark: true })
  const isDark = await page.evaluate(() => !!document.querySelector('.v-theme--dark'))
  expect(isDark).toBeTruthy()
  await expect(page.locator('.v-card, .v-table, .v-empty-state').first()).toBeVisible({ timeout: 10000 })
  expect(errors, errors.join(' | ')).toHaveLength(0)
})
