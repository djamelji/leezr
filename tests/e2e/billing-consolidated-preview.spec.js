// @ts-check
// ADR-632 — « Aperçu consolidé V2 » : projection visuelle read-only des factures V1 existantes.
// Company : le propriétaire ouvre l'aperçu depuis la liste. Platform : un admin autorisé ouvre
// l'aperçu du miroir Leezr Logistics ([RECETTE] Leezr Logistics (aperçu)) et vérifie les montants
// exacts (57,00 / 11,40 / 68,40 €) + la simulation provisional hors total. Aucun encaissement.
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'

test('Company: owner opens consolidated preview from the invoice list', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/invoices', { waitUntil: 'networkidle' })

  // Access button → preview page.
  const access = page.getByRole('link', { name: /Aperçu consolidé V2/ }).first()
  await expect(access).toBeVisible({ timeout: 10000 })
  await access.click()
  await expect(page).toHaveURL(/\/company\/billing\/consolidated-preview/, { timeout: 8000 })

  // Non-accounting warning is prominent.
  await expect(page.getByText(/ne constitue pas une facture/i)).toBeVisible({ timeout: 10000 })
  // At least one section + a monetary total render.
  await expect(page.getByText(/Total .*des factures existantes/i).first()).toBeVisible()
  await expect(page.getByText(/€/).first()).toBeVisible()

  // Dark mode: no horizontal overflow.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(overflow, 'no horizontal overflow').toBeFalsy()

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('Platform: consolidated preview of Leezr Logistics mirror shows exact amounts + provisional apart', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginPlatform(page, 'admin@leezr.com')

  // Resolve the mirror company id from its seeded invoice (read-only API, authenticated as admin).
  const companyId = await page.evaluate(async () => {
    const r = await fetch('/api/platform/billing/invoices?search=INV-APERCU-0001', { headers: { Accept: 'application/json' } })
    const j = await r.json()
    const rows = j.data || j.invoices?.data || j.invoices || []

    return rows.find(i => i.company?.id)?.company?.id ?? null
  })
  expect(companyId, 'mirror company resolved').toBeTruthy()

  await page.goto(`/platform/billing/consolidated-preview?company=${companyId}`, { waitUntil: 'networkidle' })

  // Warning + three prestations + "à confirmer" periods.
  await expect(page.getByText(/ne constitue pas une facture/i)).toBeVisible({ timeout: 10000 })
  await expect(page.getByText(/Période à confirmer/i).first()).toBeVisible()

  // Exact existing-total TTC = 68,40 € (never 103,20 €).
  const body = await page.locator('body').innerText()
  expect(body).toMatch(/68[.,]40/)
  expect(body, 'provisional must NOT be added to the receivables total').not.toMatch(/103[.,]20/)

  // Provisional Pro shown separately, not billed, indicative 29,00.
  await expect(page.getByText(/Simulation non facturée/i)).toBeVisible()
  expect(body).toMatch(/29[.,]00/)

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
