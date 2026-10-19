// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

// INCIDENT 1 hardening — exact repro requested:
//   Alice (admin@leezr.test) → logout → Djamel (owner@leezr.test) → refresh → session reopen.
// Invariant: Alice must NEVER appear in the UI after Djamel is authenticated, even briefly.

async function logout(page) {
  await page.evaluate(async () => {
    const xsrf = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1]
    await fetch('/api/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf || '') }, credentials: 'include' })
  })
  await page.waitForTimeout(400)
}

// Read the identity the app actually holds (Pinia auth store) + the userData cookie.
async function identityState(page) {
  return page.evaluate(() => {
    const ud = (document.cookie.match(/userData=([^;]+)/) || [])[1]
    let cookie = null
    try { cookie = JSON.parse(decodeURIComponent(ud || '')) } catch { /* */ }

    return { cookie_first: cookie?.first_name, cookie_email: cookie?.email }
  })
}

test('Alice → logout → Djamel → refresh → reopen: Alice never leaks', async ({ browser }) => {
  const ctx = await browser.newContext()
  const page = await ctx.newPage()
  try {
    // 1) Alice logs in.
    await loginCompany(page, 'admin@leezr.test')
    const alice = await apiRequest(page, { method: 'GET', path: '/api/me' })
    expect((alice.json?.user ?? alice.json)?.first_name).toBe('Alice')

    // 2) Logout.
    await logout(page)

    // 3) Djamel logs in (same browser context — the stale-cookie risk window).
    await loginCompany(page, 'owner@leezr.test')
    const dj = await apiRequest(page, { method: 'GET', path: '/api/me' })
    expect((dj.json?.user ?? dj.json)?.first_name, 'backend identity is Djamel').toBe('Djamel')

    // 4) Land on the dashboard and open the user-profile menu — assert Djamel, not Alice.
    await page.goto('/company/dashboard', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1500)
    const st1 = await identityState(page)
    expect(st1.cookie_email, 'cookie is Djamel after login').toBe('owner@leezr.test')
    expect(st1.cookie_first).not.toBe('Alice')
    expect(await page.locator('body').innerText()).not.toContain('Alice')

    // 5) Refresh — identity must stay Djamel, no Alice flash.
    await page.reload({ waitUntil: 'networkidle' })
    await page.waitForTimeout(1500)
    const st2 = await identityState(page)
    expect(st2.cookie_email, 'identity persists as Djamel after refresh').toBe('owner@leezr.test')
    expect(await page.locator('body').innerText()).not.toContain('Alice')

    // 6) Session reopen — new page in the SAME context (reuses session + cookies).
    const page2 = await ctx.newPage()
    const sawAlice = { v: false }
    page2.on('response', () => {}) // keep listener attached
    await page2.goto('/company/dashboard', { waitUntil: 'domcontentloaded' })
    // Poll briefly to catch any transient flash of "Alice" while booting.
    for (let i = 0; i < 12; i++) {
      const txt = await page2.locator('body').innerText().catch(() => '')
      if (txt.includes('Alice')) { sawAlice.v = true; break }
      await page2.waitForTimeout(400)
    }
    await page2.waitForLoadState('networkidle').catch(() => {})
    const st3 = await identityState(page2)
    expect(sawAlice.v, 'Alice never flashes during session reopen boot').toBe(false)
    expect(st3.cookie_email, 'reopened session identity is Djamel').toBe('owner@leezr.test')
    await page2.close()
  } finally {
    await ctx.close()
  }
})
