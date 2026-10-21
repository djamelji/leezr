// @ts-check
// Phase E — Platform "Anomalies & reconciliation" tab + AR aging (ADR-583/586). Read-only
// governance: ledger↔documents reconciliation, anomalies with severity, and aging by due date.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

async function api(page, path) {
  return page.evaluate(async p => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const r = await fetch(p, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''), Accept: 'application/json' }, credentials: 'include' })
    let j = null; try { j = await r.json() } catch {}

    return { status: r.status, json: j }
  }, path)
}

test('reconciliation tab renders summary, anomalies and aging', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/reconciliation', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  await expect(page.locator('[data-test="billing-reconciliation-tab"]')).toBeVisible({ timeout: 10000 })
  await expect(page.locator('[data-test="reconciliation-summary"]')).toBeVisible()
  await expect(page.locator('[data-test="reconciliation-anomalies"]')).toBeVisible()
  await expect(page.locator('[data-test="reconciliation-aging"]')).toBeVisible()
})

test('aging endpoint: net recoverable excludes written-off, integer cents, per currency', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/billing/reconciliation', { waitUntil: 'networkidle' })

  const res = await api(page, '/api/platform/billing/aging')
  expect(res.status).toBe(200)
  for (const c of res.json.by_currency || []) {
    const b = c.buckets
    const recoverable = b.not_due + b.d0_30 + b.d31_60 + b.d61_90 + b.d90_plus
    expect(c.net_recoverable_cents).toBe(recoverable)
    expect(c.total_documentary_cents).toBe(recoverable + b.written_off)
    expect(Number.isInteger(c.net_recoverable_cents)).toBe(true)
  }
})

test('reconciliation + aging endpoints require authentication', async ({ page }) => {
  await page.goto('/platform/login', { waitUntil: 'domcontentloaded' })
  for (const p of ['/api/platform/billing/reconciliation', '/api/platform/billing/aging']) {
    const res = await api(page, p)
    expect([401, 403].includes(res.status), `${p} gated (got ${res.status})`).toBe(true)
  }
})
