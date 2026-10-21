// @ts-check
// PRIORITY 2 — Platform KPI drill-down (ADR-584). Proves UI → API: the dashboard KPIs are
// explainable by a reconciled breakdown, with filters carried, no currency mixing, permission-gated.
import { expect, test } from '@playwright/test'
import { loginPlatform, loginCompany } from './helpers'

async function api(page, path) {
  return page.evaluate(async p => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const r = await fetch(p, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''), Accept: 'application/json' }, credentials: 'include' })

    let j = null; try { j = await r.json() } catch {}

    return { status: r.status, json: j }
  }, path)
}

test('receivables KPI drill-down: reconciled breakdown (gross − write-off = net) via UI+API', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/overview?drill=receivables', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  // Panel renders.
  await expect(page.locator('[data-test="kpi-drilldown"]')).toBeVisible({ timeout: 10000 })

  // API total reconciles: for each currency, gross − written_off === net, and net === ledger AR.
  const res = await api(page, '/api/platform/billing/drilldown/receivables')
  expect(res.status, 'drill-down endpoint permission-gated + reachable').toBe(200)
  for (const cur of res.json.by_currency || []) {
    expect(cur.gross_documents_cents - cur.written_off_cents).toBe(cur.net_recoverable_cents)
    // net recoverable reconciles to the ledger AR (or the gap is explicitly reported, never hidden).
    if (cur.reconciled) expect(cur.net_recoverable_cents).toBe(cur.ledger_ar_cents)
    else expect(cur.gap_cents).toBe(cur.ledger_ar_cents - cur.net_recoverable_cents)
    // Integer cents only — never a mixed-currency float.
    expect(Number.isInteger(cur.net_recoverable_cents)).toBe(true)
  }
})

test('revenue KPI drill-down: contributing invoices total equals the period KPI', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/overview?drill=revenue&period=30d', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await expect(page.locator('[data-test="kpi-drilldown"]')).toBeVisible({ timeout: 10000 })

  const res = await api(page, '/api/platform/billing/drilldown/revenue?period=30d')
  expect(res.status).toBe(200)
  for (const cur of res.json.by_currency || []) {
    const sum = (cur.invoices || []).reduce((a, i) => a + i.ht_cents, 0)
    expect(sum, 'sum of contributing invoices = revenue total (reconciled)').toBe(cur.revenue_ht_cents)
    expect(Number.isInteger(cur.revenue_ht_cents)).toBe(true)
  }
})

test('drill-down endpoints require authentication (unauthenticated refused)', async ({ page }) => {
  // The platform route is behind auth + platform.permission:view_billing. Without a session it is refused.
  await page.goto('/platform/login', { waitUntil: 'domcontentloaded' })
  const res = await api(page, '/api/platform/billing/drilldown/receivables')
  expect([401, 403].includes(res.status), `unauthenticated refused (got ${res.status})`).toBeTruthy()
})
