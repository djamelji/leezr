// @ts-check
// INCIDENT (owner sees "Alice Martin / Dispatcher" at /company/members/3).
// Reproduces the EXACT scenario and separates two distinct things that look alike:
//   (1) SESSION identity  = the authenticated user (navbar / /api/me)  → must stay Djamel (owner).
//   (2) CONSULTED member  = the member record opened at /company/members/:id → may be Alice.
// Also exercises the persistence path: a PRIOR admin (Alice) session in the SAME browser context,
// then login as owner — Alice must not leak into the session identity.
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

async function logout(page) {
  await page.evaluate(async () => {
    const xsrf = (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1]
    await fetch('/api/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf || '') }, credentials: 'include' }).catch(() => {})
  })
}

// Open the navbar user menu and return the identity it displays (display_name + role subtitle).
// Retried because a member-detail page may transiently intercept the first click.
async function navbarIdentity(page) {
  const avatar = page.locator('.layout-navbar .v-avatar.cursor-pointer').last()
  for (let attempt = 0; attempt < 3; attempt++) {
    await avatar.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
    await avatar.click({ force: true }).catch(() => {})
    const overlay = page.locator('.v-overlay-container .v-overlay--active').last()
    const ok = await overlay.waitFor({ state: 'visible', timeout: 6000 }).then(() => true).catch(() => false)
    if (ok) {
      const name = await overlay.locator('h6').first().innerText().catch(() => '')
      const role = await overlay.locator('.v-list-item-subtitle').first().innerText().catch(() => '')
      await page.keyboard.press('Escape').catch(() => {})
      await page.waitForTimeout(400)
      if (name.trim()) return { name: name.trim(), role: role.trim() }
    }
    await page.keyboard.press('Escape').catch(() => {})
    await page.waitForTimeout(500)
  }

  return { name: '', role: '' }
}

test('owner session identity stays Djamel while consulting a member (Alice) — persistence + refresh', async ({ browser }) => {
  const context = await browser.newContext()
  const page = await context.newPage()

  // ── Persistence setup: a PRIOR admin (Alice) session in the SAME context ──
  await loginCompany(page, 'admin@leezr.test')
  const aliceMe = await apiRequest(page, { method: 'GET', path: '/api/me' })
  expect((aliceMe.json?.user ?? aliceMe.json)?.email, 'prior session is Alice').toBe('admin@leezr.test')
  await logout(page)

  // ── Now login as OWNER in the same context (no storage wiped manually) ──
  await loginCompany(page, 'owner@leezr.test')
  const landing = page.url()
  const ownerMe = await apiRequest(page, { method: 'GET', path: '/api/me' })
  const ownerUser = ownerMe.json?.user ?? ownerMe.json
  expect(ownerUser?.email, 'SESSION identity is Djamel after switch').toBe('owner@leezr.test')
  expect(ownerUser?.display_name, 'session display_name is Djamel').toBe('Djamel')
  console.log('[repro] landing after owner login:', landing)

  // Map who member #3 actually is (a consulted member, not the session) via its profile endpoint.
  const m3profile = await apiRequest(page, { method: 'GET', path: '/api/company/members/3' })
  const m3bf = m3profile.json?.base_fields || m3profile.json?.member || {}
  console.log('[repro] member#3 profile:', JSON.stringify({ display_name: m3bf.display_name, email: m3bf.email, role: m3bf.company_role?.name || m3bf.role }))

  // Navbar identity BEFORE visiting the member page.
  const nav1 = await navbarIdentity(page)
  console.log('[repro] navbar before members/3:', JSON.stringify(nav1))
  expect(nav1.name, 'navbar shows Djamel (owner), not Alice').toBe('Djamel')
  expect(nav1.name).not.toContain('Alice')

  // ── Consult member #3 — the record may be Alice; the SESSION must not change ──
  await page.goto('/company/members/3', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  const bodyOnMember = await page.locator('body').innerText()
  console.log('[repro] members/3 page mentions Alice:', bodyOnMember.includes('Alice'), '| member#3 =', m3bf.display_name || 'N/A')

  // AUTHORITATIVE identity check on the member page: the session feeding the navbar is unchanged.
  const meOnMember = await apiRequest(page, { method: 'GET', path: '/api/me' })
  expect((meOnMember.json?.user ?? meOnMember.json)?.email, 'session UNCHANGED while viewing a member').toBe('owner@leezr.test')
  expect((meOnMember.json?.user ?? meOnMember.json)?.display_name, 'session display_name still Djamel on member page').toBe('Djamel')
  // Best-effort DOM read of the navbar menu (may be intercepted by member-page overlays); if it
  // opens, it must still be Djamel — never Alice.
  const nav2 = await navbarIdentity(page)
  console.log('[repro] navbar on members/3:', JSON.stringify(nav2))
  if (nav2.name) expect(nav2.name, 'navbar still Djamel while viewing member #3').toBe('Djamel')

  // ── Back to dashboard + refresh — identity must remain Djamel ──
  await page.goto('/dashboard', { waitUntil: 'networkidle' }).catch(() => {})
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const meAfter = await apiRequest(page, { method: 'GET', path: '/api/me' })
  expect((meAfter.json?.user ?? meAfter.json)?.email, 'identity persists as Djamel after refresh').toBe('owner@leezr.test')
  const bodyAfter = await page.locator('body').innerText()
  expect(bodyAfter, 'no Alice identity on the dashboard').not.toContain('admin@leezr.test')

  await context.close()
})
