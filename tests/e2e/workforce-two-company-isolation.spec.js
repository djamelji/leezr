// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

// LOT I — the headline Workforce 2.0 proof: ONE installation serving TWO companies with different
// trades and conventions, each with its OWN server-resolved break rule, fully isolated.
//   Company A (owner@leezr.test)  → break target 30 min
//   Company B (ownerb@leezr.test) → break target 45 min
// Prereq fixture: `php artisan e2e:setup-two-companies` (local/testing only).

function readCompanyId(page) {
  return page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || ''))
}

function breakTarget(res) {
  const rule = (res.json?.rules || []).find(r => r.key === 'target_duration_minutes')

  return rule ? rule.value : null
}

test('two companies have isolated, server-resolved break targets (30 vs 45) and cannot cross tenants', async ({ page }) => {
  // ── Company A resolves its own 30-minute target, server-side ──
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  const idA = await readCompanyId(page)
  const aCfg = await apiRequest(page, { method: 'GET', path: '/api/workforce/config/breaks' })
  expect(aCfg.status, 'A can read its own config').toBeLessThan(400)
  expect(breakTarget(aCfg), 'Company A break target = 30').toBe(30)

  // ── Switch to Company B — a different trade + convention — resolves 45 ──
  await page.context().clearCookies()
  await loginCompany(page, 'ownerb@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  const idB = await readCompanyId(page)
  expect(idB, 'distinct company contexts').not.toBe(idA)

  const bCfg = await apiRequest(page, { method: 'GET', path: '/api/workforce/config/breaks' })
  expect(bCfg.status, 'B can read its own config').toBeLessThan(400)
  expect(breakTarget(bCfg), 'Company B break target = 45').toBe(45)

  // ── Tenant isolation: as B, forcing Company A's context is refused (SetCompanyContext → 403) ──
  const cross = await page.evaluate(async foreignId => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const res = await fetch('/api/workforce/config/breaks', {
      method: 'GET',
      headers: {
        'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''),
        'X-Company-Id': foreignId,
        Accept: 'application/json',
      },
      credentials: 'include',
    })

    return res.status
  }, idA)
  expect(cross, 'B cannot act in Company A (no cross-tenant access)').toBe(403)

  // ── Coherence after refresh: B still resolves 45 server-side ──
  await page.reload({ waitUntil: 'networkidle' })
  const bCfg2 = await apiRequest(page, { method: 'GET', path: '/api/workforce/config/breaks' })
  expect(breakTarget(bCfg2), 'Company B target stable after reload').toBe(45)
})

test('each company employee clocks and sees their own break rule from the server', async ({ page }) => {
  // Company B employee (ownerb is also an employee) clocks in and the profile exposes the 45' target.
  await loginCompany(page, 'ownerb@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })

  const profile = await apiRequest(page, { method: 'GET', path: '/api/workforce/me' })
  expect(profile.status, 'B profile reachable').toBeLessThan(400)
  // The self-service profile carries the per-company resolved break rules.
  const rules = profile.json?.break_rules ?? profile.json?.data?.break_rules ?? null
  expect(rules, 'profile exposes resolved break_rules').toBeTruthy()
  expect(Number(rules.target_duration_minutes ?? rules.target ?? 0), 'B employee sees 45').toBe(45)

  // Clock in → profile reflects a working session (counter starts from the server timestamp).
  const clockIn = await apiRequest(page, { method: 'POST', path: '/api/workforce/me/clock-in' })
  expect([200, 201, 409].includes(clockIn.status), 'clock-in accepted or already clocked').toBeTruthy()
  const after = await apiRequest(page, { method: 'GET', path: '/api/workforce/me' })
  const todays = after.json?.today_clock ?? after.json?.data?.today_clock ?? null
  expect(todays, 'profile carries today_clock after clock-in').toBeTruthy()

  // Clean up the open session so the fixture stays idempotent.
  await apiRequest(page, { method: 'POST', path: '/api/workforce/me/clock-out' })
})
