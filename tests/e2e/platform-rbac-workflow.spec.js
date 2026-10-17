// @ts-check
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

// Platform RBAC lifecycle on the isolated local base, driven as super-admin (admin@leezr.com):
//   1) Role full CRUD (create → edit → delete) with persistence after reload.
//   2) Create a platform user (ANO-011 — the create drawer had no trigger) + assign a role,
//      with persistence of the assignment.
//   3) Effective enforcement: a user whose role grants NO permission is blocked by the API
//      from the manage-users area, while the super-admin is allowed. This proves the
//      permissions model shown in the UI matches what the API actually enforces.
//
// Real Sanctum platform session, real /api/platform endpoints, real persistence, no bypass.

const stamp = String(Date.now()).slice(-6)
const roleKey = `e2e_role_${stamp}`
const roleName = `E2E Role ${stamp}`
const limitedKey = `e2e_limited_${stamp}`
const limitedName = `E2E Limited ${stamp}`
const userEmail = `e2e.limited.${stamp}@leezr.com`
const userPassword = 'Lz7#qWvMx2pR'

test.describe.configure({ mode: 'serial' })
// Login is throttled (15/min per IP); the robust loginPlatform may need to ride out a
// throttle window, so allow extra time per test.
test.beforeEach(() => test.setTimeout(180000))

test('role CRUD: create → edit → delete (persisted)', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/access/roles', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  // Create
  await page.locator('[data-test="role-add"]').click()
  await page.waitForTimeout(500)
  await page.locator('[data-test="role-key"] input').fill(roleKey)
  await page.locator('[data-test="role-name"] input').fill(roleName)
  const [createResp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('/platform/roles') && r.request().method() === 'POST'),
    page.locator('[data-test="role-submit"]').click(),
  ])
  expect(createResp.status(), 'role created').toBe(201)

  // Persistence: reload → role card is present
  await page.goto('/platform/access/roles', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByText(roleName).first()).toBeVisible({ timeout: 10000 })

  // Edit: rename the role via its own card
  const card = page.locator('.v-card').filter({ hasText: roleName }).first()
  await card.locator('[data-test="role-edit"]').click()
  await page.waitForTimeout(600)
  const renamed = `${roleName} v2`
  await page.locator('[data-test="role-name"] input').fill(renamed)
  const [updateResp] = await Promise.all([
    page.waitForResponse(r => /\/platform\/roles\/\d+$/.test(r.url()) && r.request().method() === 'PUT'),
    page.locator('[data-test="role-submit"]').click(),
  ])
  expect(updateResp.status(), 'role updated').toBeLessThan(400)

  // Persistence of the edit
  await page.goto('/platform/access/roles', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByText(renamed).first()).toBeVisible({ timeout: 10000 })

  // Delete (no users assigned → delete link is available)
  const card2 = page.locator('.v-card').filter({ hasText: renamed }).first()
  await card2.locator('[data-test="role-delete"]').click()
  const [deleteResp] = await Promise.all([
    page.waitForResponse(r => /\/platform\/roles\/\d+$/.test(r.url()) && r.request().method() === 'DELETE'),
    page.getByRole('button', { name: 'Confirm' }).click(),
  ])
  expect(deleteResp.status(), 'role deleted').toBeLessThan(400)

  // Persistence of the delete
  await page.goto('/platform/access/roles', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByText(renamed)).toHaveCount(0)
})

test('user create + role assignment (persisted) — ANO-011 trigger', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')

  // First create a role with NO permissions — used for the enforcement test below.
  await page.goto('/platform/access/roles', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await page.locator('[data-test="role-add"]').click()
  await page.waitForTimeout(500)
  await page.locator('[data-test="role-key"] input').fill(limitedKey)
  await page.locator('[data-test="role-name"] input').fill(limitedName)
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/platform/roles') && r.request().method() === 'POST'),
    page.locator('[data-test="role-submit"]').click(),
  ])

  // Now create a platform user with that role + a known password (via the newly-wired button).
  await page.goto('/platform/access/users', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await page.locator('[data-test="platform-user-add"]').click()
  await page.waitForTimeout(500)
  await page.locator('[data-test="platform-user-first-name"] input').fill('E2E')
  await page.locator('[data-test="platform-user-last-name"] input').fill(`Limited${stamp}`)
  await page.locator('[data-test="platform-user-email"] input').fill(userEmail)

  // Assign the limited role via the multi-select.
  await page.locator('[data-test="platform-user-roles"]').click()
  await page.getByRole('option', { name: limitedName }).first().click()
  await page.keyboard.press('Escape')

  // Set a password so we can log in as this user in the enforcement test.
  await page.locator('[data-test="platform-user-cred-password"]').click()
  await page.locator('[data-test="platform-user-password"] input').fill(userPassword)
  await page.locator('[data-test="platform-user-password-confirm"] input').fill(userPassword)

  const [createResp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('/platform/platform-users') && r.request().method() === 'POST'),
    page.locator('[data-test="platform-user-submit"]').click(),
  ])
  expect(createResp.status(), `user created (body: ${await createResp.text().catch(() => '')})`).toBe(201)

  // Persistence: reload the users list → the new user appears with its email.
  await page.goto('/platform/access/users', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await expect(page.getByText(userEmail).first()).toBeVisible({ timeout: 10000 })

  // Positive control (reuses this admin session to avoid extra logins hitting the login
  // throttle): the super-admin IS allowed to list platform users.
  const adminStatus = await apiStatus(page, '/api/platform/platform-users')
  expect(adminStatus, 'admin can list platform users').toBe(200)
})

// Hit the real gated API with the browser's own authenticated session (cookie + XSRF token,
// exactly as the SPA does). This probes what the API ACTUALLY enforces, not what the UI shows.
// Retries past transient auth/rate-limit blips (0/401/429) so only a STABLE status is asserted —
// a transient is only tolerated if a definitive status is eventually obtained.
async function apiStatus(page, path) {
  const once = p => page.evaluate(async q => {
    const xsrf = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1]
    try {
      const res = await fetch(q, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf || ''), Accept: 'application/json' },
        credentials: 'include',
      })

      return res.status
    } catch {
      return 0
    }
  }, p)

  let status = 0
  for (let i = 0; i < 6; i++) {
    status = await once(path)
    if (status !== 0 && status !== 401 && status !== 429) break
    await page.waitForTimeout(1200)
  }

  return status
}

test('enforcement: no-permission user is blocked from the users-management API', async ({ page }) => {
  // Restricted user — their role grants NO permission → the API must refuse (403), proving the
  // permission model is enforced server-side regardless of what any form displays.
  // (The admin positive control is asserted in the previous test to keep login count low.)
  await loginPlatform(page, userEmail, userPassword)
  const limitedStatus = await apiStatus(page, '/api/platform/platform-users')
  expect(limitedStatus, `restricted user must NOT get 200 (got ${limitedStatus})`).not.toBe(200)
  expect(limitedStatus, `restricted user is forbidden (got ${limitedStatus})`).toBe(403)
})
