// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'
import { createRun } from './run-ledger.js'

// ADR-770 : données possédées par cette exécution ; démontage borné au registre.
const run = createRun('member-lifecycle')

test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })

// Full member lifecycle driven by the company owner on the isolated local base:
//   create member (role Driver) → edit role to Manager → delete — each step real API + persistence.
// Also asserts owner self-protection (the owner row exposes no delete action).
// Real Sanctum, real RBAC (members.manage), real business guards (owner cannot be removed).

test('owner: create member → change role → delete (persisted, with owner protection)', async ({ page }) => {
  const email = run.email('member')

  await loginCompany(page, 'owner@leezr.test')
  run.useCompany(await page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || '')))
  await page.goto('/company/members', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  // ─── Create (role = Driver) ───────────────────────────────────────────────
  await page.getByRole('button', { name: 'Ajouter un membre' }).click()
  await page.getByLabel('Prénom').fill('E2E')
  await page.getByLabel('Nom', { exact: true }).fill('Lifecycle')
  await page.getByLabel('E-mail').fill(email)
  await page.locator('[data-test="member-role-select"]').click()
  await page.getByRole('option', { name: 'Driver' }).first().click()
  const createdResp = await clickAndExpectApi(page, { urlIncludes: '/api/company/members', method: 'POST' },
    () => page.getByRole('button', { name: 'Ajouter', exact: true }).click())
  const createdMember = (await createdResp.json()).member

  // Inscrit au registre DÈS la création : un échec ultérieur ne laisse aucun résidu non possédé.
  run.track('membership', createdMember.id)
  run.track('user', createdMember.user.id)

  await page.goto('/company/members', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const row = page.locator('tr', { hasText: email })
  await expect(row).toBeVisible({ timeout: 10000 })
  await expect(row, 'created with Driver role').toContainText('Driver')

  // ─── Owner protection: the owner (Propriétaire) row has no delete action ────
  const ownerRow = page.locator('tr', { hasText: 'owner@leezr.test' })
  await expect(ownerRow.locator('[data-test="member-delete"]')).toHaveCount(0)

  // ─── Edit role → Manager ────────────────────────────────────────────────────
  // ADR-768 (M7 LOT 2) : /company/members/:id REDIRIGE vers la fiche Collaborateur unifiée (parité
  // prouvée) — le même formulaire (MemberProfileForm) y vit dans l'onglet « Informations ».
  const memberId = createdMember.id
  await row.locator('[data-test="member-edit"]').click()
  await page.waitForURL(/\/company\/people\/user-\d+/, { timeout: 10000 })
  await page.waitForTimeout(1200)
  await page.getByRole('tab', { name: 'Informations' }).first().click()
  await page.waitForTimeout(800)
  await page.locator('[data-test="member-profile-role"]').click()
  await page.getByRole('option', { name: 'Manager', exact: true }).first().click()
  await clickAndExpectApi(page, { urlIncludes: `/api/company/members/${memberId}`, method: 'PUT' },
    () => page.locator('[data-test="member-profile-save"]').click())

  // Persistence of the role change: reload the list → row now shows Manager.
  await page.goto('/company/members', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const row2 = page.locator('tr', { hasText: email })
  await expect(row2, 'role change persisted').toContainText('Manager')

  // ─── Delete ──────────────────────────────────────────────────────────────
  await clickAndExpectApi(page, { urlIncludes: `/api/company/members/${memberId}`, method: 'DELETE' },
    async () => {
      await row2.locator('[data-test="member-delete"]').click()
      await page.getByRole('button', { name: 'Confirm' }).click()
    })

  // Persistence of the delete.
  await page.goto('/company/members', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await expect(page.getByText(email)).toHaveCount(0)
})
