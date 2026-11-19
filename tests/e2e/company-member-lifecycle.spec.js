// @ts-check
import { expect, test } from '@playwright/test'
import { apiRequest, clickAndExpectApi, loginCompany } from './helpers'
import { createRun } from './run-ledger.js'

// ADR-770 : données possédées par cette exécution ; démontage borné au registre.
const run = createRun('member-lifecycle')

test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })

// Full member lifecycle driven by the company owner on the isolated clone:
//   create member (role Driver) → edit role to Manager → delete — each step real API + persistence.
// Also asserts owner self-protection (the owner exposes no removal and no role change).
// Real Sanctum, real RBAC (members.manage), real business guards (owner cannot be removed).
// ADR-831 (D-21 bis) — /company/members redirects to /company/people (ADR-802) : creation goes through the
// « Ajouter une personne » drawer, role and removal through the person file (« Accès & autorisations »).

const person = async (page, ref) => (await apiRequest(page, { path: `/api/company/people/${ref}` })).json
const findRef = async (page, search) =>
  (await apiRequest(page, { path: `/api/company/people?search=${encodeURIComponent(search)}` })).json?.data?.[0]?.person_ref || null
const openAccess = async page => {
  await page.getByRole('tab', { name: 'Accès & autorisations' }).first().click()
  await page.waitForTimeout(700)
}

test('owner: create member → change role → delete (persisted, with owner protection)', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const email = run.email('member')

  await loginCompany(page, 'owner@leezr.test')
  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  // ─── Create (role = Driver) ───────────────────────────────────────────────
  await page.locator('[data-test="people-add"]').click()
  await page.locator('[data-test="person-add-first-name"] input').fill('E2E')
  await page.locator('[data-test="person-add-last-name"] input').fill('Lifecycle')
  await page.locator('[data-test="person-add-access-yes"] input').check()
  await page.locator('[data-test="person-add-email"] input').fill(email)
  await page.locator('[data-test="person-add-staff"] input').uncheck()
  await page.locator('[data-test="person-add-role"]').click()
  await page.getByRole('option', { name: 'Driver' }).first().click()
  const createdResp = await clickAndExpectApi(page, { urlIncludes: '/api/company/members', method: 'POST' },
    () => page.locator('[data-test="person-add-submit"]').click())
  const createdMember = (await createdResp.json()).member

  // Inscrit au registre DÈS la création : un échec ultérieur ne laisse aucun résidu non possédé.
  run.track('membership', createdMember.id)
  run.track('user', createdMember.user.id)

  const ref = await findRef(page, email)
  expect(ref, 'listed in the unified people list').toMatch(/^user-\d+$/)
  expect((await person(page, ref)).account.role.name, 'created with Driver role').toBe('Driver')
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await page.getByPlaceholder('Rechercher un collaborateur…').fill(email)
  await expect(page.locator('tr', { hasText: email }), 'visible after reload').toBeVisible({ timeout: 10000 })

  // ─── Owner protection: the owner exposes neither removal nor role change ────
  const ownerRef = await findRef(page, 'owner@leezr.test')
  await page.goto(`/company/people/${ownerRef}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await openAccess(page)
  await expect(page.locator('[data-test="person-remove"]'), 'owner cannot be removed').toHaveCount(0)
  await expect(page.locator('[data-test="person-role-select"]'), 'owner role is not editable').toHaveCount(0)

  // ─── Edit role → Manager ────────────────────────────────────────────────────
  const memberId = createdMember.id
  await page.goto(`/company/people/${ref}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await openAccess(page)
  await page.locator('[data-test="person-role-select"]').click()
  await page.getByRole('option', { name: 'Manager', exact: true }).first().click()
  await clickAndExpectApi(page, { urlIncludes: `/api/company/members/${memberId}`, method: 'PUT' },
    () => page.locator('[data-test="person-role-save"]').click())

  // Persistence of the role change: reload → Manager.
  await page.reload({ waitUntil: 'networkidle' })
  expect((await person(page, ref)).account.role.name, 'role change persisted').toBe('Manager')

  // ─── Delete ──────────────────────────────────────────────────────────────
  await openAccess(page)
  await clickAndExpectApi(page, { urlIncludes: `/api/company/members/${memberId}`, method: 'DELETE' },
    async () => {
      await page.locator('[data-test="person-remove"]').click()
      await page.getByRole('button', { name: 'Confirm' }).click()
    })

  // Persistence of the delete.
  await page.waitForURL(/\/company\/people(\?.*)?$/, { timeout: 15000 })
  expect(await findRef(page, email), 'removed : no longer listed').toBeNull()
})
