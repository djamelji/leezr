// @ts-check
// Leezr 4.2 M1 (ADR-837) — browser recipe with REAL clicks on the marked clone (global-setup, ADR-831).
// Fixture : scripts/audit842-m1-fixture.php (fictitious company 106 = Pro ANNUAL, read-only operator viewer842).
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers.js'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M1 recipe only (AUDIT_PASSWORD_FILE)')
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m1'
const COMPANY = 106
const OWNER = process.env.M1_OWNER || 'owner.at4f8@audit842.test'
const shot = (page, name) => page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 })
const apiErrors = page => {
  const list = []
  page.on('response', r => { if (r.status() >= 400 && r.url().includes('/api/')) list.push(`${r.status()} ${r.url().replace(/^https?:\/\/[^/]+/, '')}`) })

  return list
}


// ADR-850 (M10) : the recipe prepares itself — no order, no state inherited from another recipe
test.beforeAll(() => { prepare('m1') })
test.beforeEach(({ page }) => { page.setDefaultTimeout(15000); fs.mkdirSync(OUT, { recursive: true }) })

test('M1-A/B — liste en lecture seule, changement contrôlé depuis la fiche (annuel conservé)', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  const errors = apiErrors(page)
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)

  // A — list : current plan shown, no selector
  await page.goto('/platform/companies', { waitUntil: 'networkidle' })
  const chip = page.getByTestId(`company-plan-${COMPANY}`)
  await page.getByPlaceholder(/Rechercher/).first().fill('Audit 842 Services')
  await page.waitForTimeout(1500)
  await expect(chip).toBeVisible()
  await expect(chip).toHaveText(/Pro/)
  const row = page.locator('tr', { has: chip })
  expect(await row.locator('.v-select').count(), 'no plan selector left in the list').toBe(0)
  await shot(page, 'A-liste-plan-lecture-seule')

  // B — company record › Subscription & billing › plan selector → preview → confirm (moved there in ADR-842 / M5)
  await page.goto(`/platform/companies/${COMPANY}/billing`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  // the label is not linked to the field (a11y gap UXP-15) : target the plan combobox by its value
  await page.getByTestId('company-plan-change').locator('.v-select').getByRole('combobox').first().click()
  await page.locator('.v-overlay--active .v-list-item', { hasText: 'Business' }).first().click()
  const dialog = page.locator('.v-overlay--active .v-card', { hasText: 'Aperçu du changement de plan' })
  await expect(dialog).toBeVisible()
  await page.waitForTimeout(1500)
  const text = await dialog.innerText()
  expect(text, 'the preview shows the annual price (interval kept)').toMatch(/\/\s*an/)
  expect(text).not.toMatch(/\/\s*mois/)
  await shot(page, 'B1-apercu-annuel-conserve')
  await dialog.getByRole('button', { name: /Confirmer/ }).click()
  await page.waitForTimeout(2500)
  await shot(page, 'B2-apres-confirmation')
  expect(errors.filter(e => !e.includes('/plan-preview') || !e.startsWith('422')), 'no API error during the journey').toEqual([])
})

test('M1-C — historique de facturation Company affiché', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const errors = apiErrors(page)
  await loginCompany(page, OWNER, PASSWORD)
  await page.goto('/company/billing/activity', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByTestId('billing-timeline-error')).toHaveCount(0)
  await expect(page.locator('.v-timeline-item').first()).toBeVisible()
  await expect(page.getByTestId('billing-timeline-detail').first()).toContainText('Pro → Business')
  await shot(page, 'C-historique-facturation')
  expect(errors).toEqual([])
})

test('M1-D — tableau de bord Platform lisible', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await page.goto('/platform/dashboard/overview', { waitUntil: 'networkidle' })
  await page.waitForTimeout(2500)
  const body = await page.locator('.layout-page-content').innerText()
  // V35 cockpit (ADR-855) : the invoiced-this-month tile keeps the ADR-841 wording — invoiced, never « collected » (ADR-862)
  await expect(page.locator('[data-test="cockpit-invoiced-mtd"]')).toContainText('Facturé ce mois (HT)')
  await expect(page.locator('[data-test="cockpit-mrr"]')).toBeVisible()
  for (const bad of ['companies', 'a valider', 'Funnel', 'Sante systeme', 'echoues', 'workforce_leave', 'core.documentation', 'Scheduler', 'non assignés', '(7j)'])
    expect(body, `no « ${bad} » left`).not.toContain(bad)
  await shot(page, 'D-tableau-de-bord')
  await page.mouse.wheel(0, 700)
  await page.waitForTimeout(800)
  await shot(page, 'D2-tableau-de-bord-bas')
})

test('M1-E — un opérateur sans permission commerciale ne peut pas changer le plan', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginPlatform(page, 'viewer842@platform.test', PASSWORD)
  await page.goto(`/platform/companies/${COMPANY}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByRole('tab', { name: /Identité et adresses/ })).toHaveCount(0)
  await page.goto(`/platform/companies/${COMPANY}/billing`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  expect(await page.getByTestId('company-plan-change').count(), 'no plan change without manage_companies').toBe(0)
  const status = await page.evaluate(async id => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
    const r = await fetch(`/api/platform/companies/${id}/plan`, { method: 'PUT', credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }, body: JSON.stringify({ plan_key: 'starter' }) })

    return r.status
  }, COMPANY)
  expect(status, 'server refuses the change').toBe(403)
  await shot(page, 'E-lecteur-sans-droit')
})
