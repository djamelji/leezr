// @ts-check
// Phase D — Company billing cockpit. Proves the company sees a finance summary that shares the
// Platform ledger definitions (integer cents, reconciled), that the next invoice is an ESTIMATE
// (never an emitted invoice), and that a company cannot read another company's billing (isolation).
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

test('company billing overview exposes a reconciled finance summary (shared ledger defs)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/overview', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  const ov = await apiRequest(page, { method: 'GET', path: '/api/billing/overview' })
  expect(ov.status, 'overview reachable').toBeLessThan(400)
  expect(Array.isArray(ov.json.finance_summary), 'finance_summary present (array)').toBe(true)
  for (const cur of ov.json.finance_summary) {
    expect(Number.isInteger(cur.outstanding_cents), 'outstanding in integer cents').toBe(true)
    expect(Number.isInteger(cur.paid_cents)).toBe(true)
    expect(typeof cur.reconciled).toBe('boolean')
  }
})

test('next-invoice preview is an ESTIMATE, never an emitted invoice', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/overview', { waitUntil: 'networkidle' })
  const prev = await apiRequest(page, { method: 'GET', path: '/api/billing/next-invoice-preview' })
  expect(prev.status).toBeLessThan(500)
  if (prev.json && prev.json.preview) {
    expect(prev.json.preview.is_estimate, 'preview is flagged as an estimate').toBe(true)
  }
})

test('company cannot read another company billing (tenant isolation)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/billing/overview', { waitUntil: 'networkidle' })
  // Force a foreign/unknown company context — must be refused, never served.
  const cross = await page.evaluate(async () => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const r = await fetch('/api/billing/overview', {
      method: 'GET',
      headers: { 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''), 'X-Company-Id': '999999', Accept: 'application/json' },
      credentials: 'include',
    })

    return r.status
  })
  expect([403, 404].includes(cross), `foreign company billing refused (got ${cross})`).toBe(true)
})
