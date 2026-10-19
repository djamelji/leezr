// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

// INCIDENT 1 non-regression — authenticated identity is always the logged-in account's own,
// never another user's, across truly separate browser sessions, navigation, refresh and
// re-login with a second account. owner@leezr.test = Djamel ; bob@leezr.test = Bob Dupont ;
// admin@leezr.test = Alice Martin (must NEVER appear for owner/bob).

async function me(page) {
  const r = await apiRequest(page, { method: 'GET', path: '/api/me' })
  const u = r.json?.user ?? r.json ?? {}

  return { status: r.status, email: u.email, first: u.first_name, last: u.last_name, id: u.id }
}

test('owner and bob keep their own identity in isolated sessions (no leak)', async ({ browser }) => {
  const ownerCtx = await browser.newContext()
  const bobCtx = await browser.newContext()
  const owner = await ownerCtx.newPage()
  const bob = await bobCtx.newPage()
  try {
    await loginCompany(owner, 'owner@leezr.test')
    const m1 = await me(owner)
    expect(m1.email, 'owner identity').toBe('owner@leezr.test')
    expect(m1.first).toBe('Djamel')
    expect(`${m1.first} ${m1.last}`).not.toContain('Alice')

    await loginCompany(bob, 'bob@leezr.test')
    const mb = await me(bob)
    expect(mb.email, 'bob identity').toBe('bob@leezr.test')
    expect(mb.first).toBe('Bob')

    // Navigation + refresh must preserve owner identity (no contamination from bob's session).
    await owner.goto('/company/dashboard', { waitUntil: 'networkidle' })
    await owner.reload({ waitUntil: 'networkidle' })
    const m2 = await me(owner)
    expect(m2.email, 'owner identity after bob login + refresh').toBe('owner@leezr.test')
    expect(m2.id).toBe(m1.id)
  } finally {
    await ownerCtx.close()
    await bobCtx.close()
  }
})

test('logout then login as a second account switches identity cleanly', async ({ page }) => {
  // Login as owner (Djamel).
  await loginCompany(page, 'owner@leezr.test')
  expect((await me(page)).email).toBe('owner@leezr.test')

  // Logout, then login as admin (Alice Martin) in the SAME browser context.
  await page.evaluate(async () => {
    const xsrf = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1]
    await fetch('/api/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf || '') }, credentials: 'include' })
  })
  await page.waitForTimeout(500)
  await loginCompany(page, 'admin@leezr.test')

  const after = await me(page)
  expect(after.email, 'identity switched to admin').toBe('admin@leezr.test')
  expect(`${after.first} ${after.last}`).toBe('Alice Martin')
  // And the previous owner identity must be gone.
  expect(after.email).not.toBe('owner@leezr.test')
})
