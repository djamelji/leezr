// @ts-check
// Remote smoke (DEV/PROD): fully self-contained — exercises ONLY the deployed app's own API,
// so browser + API + DB are all the same remote environment. No local artisan, no local DB.
// Non-destructive: clocks in then clocks back out; reads config/profile; probes tenant isolation.
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

test('workforce resolver + clock server-truth + tenant isolation (remote)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })

  // 1) Rules engine resolves break config for this company (resolver reachable on the deployed stack).
  const cfg = await apiRequest(page, { method: 'GET', path: '/api/workforce/config/breaks' })
  expect(cfg.status, 'config/breaks reachable').toBeLessThan(400)
  const keys = (cfg.json?.rules || []).map(r => r.key)
  expect(keys, 'resolved break rules include the legal minimum + target').toEqual(
    expect.arrayContaining(['min_duration_minutes', 'target_duration_minutes']),
  )

  // 2) Work-time rules are also resolved (LOT E/H wiring present on the deployed build).
  const wt = await apiRequest(page, { method: 'GET', path: '/api/workforce/config/work-time' })
  expect(wt.status, 'config/work-time reachable').toBeLessThan(400)
  expect((wt.json?.rules || []).map(r => r.key), 'work-time rules resolved').toEqual(
    expect.arrayContaining(['weekly_hours_reference', 'daily_rest_min_hours']),
  )

  // 3) Tenant isolation: forcing a foreign company context is refused (SetCompanyContext → 403).
  const cross = await page.evaluate(async () => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const res = await fetch('/api/workforce/config/breaks', {
      method: 'GET',
      headers: {
        'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''),
        'X-Company-Id': '999999',
        Accept: 'application/json',
      },
      credentials: 'include',
    })

    return res.status
  })
  // A foreign/unknown company context must NEVER serve data: 403 (not a member) or 404 (no such
  // company). The 403-for-a-real-foreign-company case is proven in the two-company isolation spec.
  expect([403, 404], 'foreign company context refused, never served').toContain(cross)
  expect(cross, 'foreign context never returns data').not.toBe(200)
})

test('clock counter is server-truth and stable across reads (remote)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })

  // Ensure a clean state, then clock in.
  await apiRequest(page, { method: 'POST', path: '/api/workforce/me/clock-out' })
  const clockIn = await apiRequest(page, { method: 'POST', path: '/api/workforce/me/clock-in' })
  expect([200, 201].includes(clockIn.status), 'clock-in accepted').toBeTruthy()

  // The worked counter is derived from the SERVER clock_in timestamp — it must be identical on a
  // second read (and therefore survive a frontend refresh, which just re-reads the same server value).
  const first = await apiRequest(page, { method: 'GET', path: '/api/workforce/me' })
  const inA = first.json?.today_clock?.clock_in ?? null
  expect(inA, 'today_clock.clock_in present after clock-in').toBeTruthy()

  await page.reload({ waitUntil: 'networkidle' })
  const second = await apiRequest(page, { method: 'GET', path: '/api/workforce/me' })
  const inB = second.json?.today_clock?.clock_in ?? null
  expect(inB, 'clock_in stable across refresh (server-truth, no reset to zero)').toBe(inA)

  // Clean up — clock back out so no open session persists in staging data.
  await apiRequest(page, { method: 'POST', path: '/api/workforce/me/clock-out' })
})
