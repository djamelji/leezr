// @ts-check
// Real browser verification of the Platform reconciliation PAGE (route /platform/billing/:tab with
// tab=reconciliation) — NOT just the API. Read-only: login + navigation + reads only, no financial
// writes. Env-agnostic & replayable: expected amounts are derived from the API responses (never
// hard-coded), and the rendered figures are asserted to MATCH them. Captures console/page/network
// errors. Logs only error texts and URLs — never cookies, tokens or credentials.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

// Parse a displayed money string ("68,40 €", "€68.40", "-34,80 €") to integer cents, locale-tolerant.
function parseMoneyToCents(text) {
  const neg = /[-−]/.test(text)
  let s = (text || '').replace(/[^\d.,]/g, '')
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.')
  const v = parseFloat(s || '0')

  return Math.round((neg ? -1 : 1) * v * 100)
}

async function apiJson(page, path) {
  return page.evaluate(async p => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const r = await fetch(p, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''), Accept: 'application/json' }, credentials: 'include' })

    return { status: r.status, json: await r.json().catch(() => null) }
  }, path)
}

test('reconciliation page renders for real and its figures match the reconciliation API', async ({ page }, testInfo) => {
  const consoleErrors = []
  const pageErrors = []
  const badResponses = []
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()) })
  page.on('pageerror', e => pageErrors.push(String(e)))
  page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400) badResponses.push(`${r.status()} ${r.url()}`) })

  await loginPlatform(page, 'admin@leezr.com')

  // 1) Direct URL must render a real page (not 404 / login redirect / empty / error).
  await page.goto('/platform/billing/reconciliation', { waitUntil: 'networkidle' })
  await page.waitForTimeout(2000)
  expect(page.url(), 'URL stays on the reconciliation tab (no redirect)').toContain('/platform/billing/reconciliation')
  await expect(page.locator('[data-test="billing-reconciliation-tab"]'), 'tab content rendered').toBeVisible({ timeout: 10000 })
  await expect(page.locator('[data-test="reconciliation-summary"]')).toBeVisible()
  await expect(page.locator('[data-test="reconciliation-anomalies"]')).toBeVisible()
  await expect(page.locator('[data-test="reconciliation-aging"]')).toBeVisible()

  // Derive expectations from the actual endpoints (no hard-coded PROD amounts).
  const recon = await apiJson(page, '/api/platform/billing/reconciliation')
  const aging = await apiJson(page, '/api/platform/billing/aging')
  expect(recon.status, 'reconciliation endpoint reachable').toBe(200)
  expect(aging.status, 'aging endpoint reachable').toBe(200)

  // 2) For each currency with data, the RENDERED summary row must match the endpoint (cents).
  for (const c of recon.json.by_currency || []) {
    const row = page.locator('[data-test="reconciliation-summary"] tbody tr', { hasText: c.currency }).first()
    await expect(row, `summary row for ${c.currency} rendered`).toBeVisible()
    const cells = row.locator('td')
    expect(parseMoneyToCents(await cells.nth(1).innerText()), `rendered ledger AR = API (${c.currency})`).toBe(c.ledger_ar)
    expect(parseMoneyToCents(await cells.nth(2).innerText()), `rendered document balances = API (${c.currency})`).toBe(c.invoices_outstanding)
  }

  // 3) Every anomaly returned by the API is visible on the page (nothing masked).
  for (const a of recon.json.anomalies || []) {
    await expect(page.locator(`[data-test="anomaly-${a.type}"]`).first(), `anomaly ${a.type} visible`).toBeVisible()
  }

  // 4) Aging: rendered net recoverable matches the endpoint, per currency.
  for (const c of aging.json.by_currency || []) {
    const cell = page.locator(`[data-test="aging-net-${c.currency}"]`).first()
    if (await cell.count()) {
      expect(parseMoneyToCents(await cell.innerText()), `rendered aging net = API (${c.currency})`).toBe(c.net_recoverable_cents)
    }
  }

  // 5) Invoice reference links work when an anomaly carries an invoice.
  const withInvoice = (recon.json.anomalies || []).find(a => a.invoice_id)
  if (withInvoice) {
    const link = page.locator(`[data-test="anomaly-${withInvoice.type}"] a`).first()
    await expect(link, 'invoice link rendered').toBeVisible()
    expect(await link.getAttribute('href'), 'link targets the invoice detail route').toContain('/platform/billing/invoices/')
  }

  // 6) Responsive: still renders on a mobile viewport.
  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(400)
  await expect(page.locator('[data-test="billing-reconciliation-tab"]')).toBeVisible()
  await page.setViewportSize({ width: 1440, height: 900 })

  // 7) Screenshot proof.
  await page.screenshot({ path: testInfo.outputPath('reconciliation-page.png'), fullPage: true })

  // 8) No JS / page / server errors (log only error texts + URLs — never secrets).
  console.log('[verify] consoleErrors:', JSON.stringify(consoleErrors.slice(0, 5)))
  console.log('[verify] pageErrors:', JSON.stringify(pageErrors.slice(0, 5)))
  console.log('[verify] bad /api responses:', JSON.stringify(badResponses.slice(0, 5)))
  expect(pageErrors, 'no uncaught JS exceptions').toEqual([])
  expect(badResponses.filter(r => /^5\d\d /.test(r)), 'no 5xx API errors').toEqual([])
})

test('navigation from /platform/billing/overview to the reconciliation tab works', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/overview', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  const tab = page.getByRole('tab', { name: /Anomalies|rapprochement|reconciliation/i }).first()
  await expect(tab, 'reconciliation tab present in the tab bar').toBeVisible({ timeout: 10000 })
  await tab.click()
  await page.waitForTimeout(1200)
  expect(page.url()).toContain('/platform/billing/reconciliation')
  await expect(page.locator('[data-test="billing-reconciliation-tab"]')).toBeVisible()
})
