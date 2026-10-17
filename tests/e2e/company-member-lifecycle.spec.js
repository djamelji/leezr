// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'

// Full member lifecycle driven by the company owner on the isolated local base:
//   create member (role Driver) → edit role to Manager → delete — each step real API + persistence.
// Also asserts owner self-protection (the owner row exposes no delete action).
// Real Sanctum, real RBAC (members.manage), real business guards (owner cannot be removed).

test('owner: create member → change role → delete (persisted, with owner protection)', async ({ page }) => {
  const email = `e2e.member.${String(Date.now()).slice(-7)}@leezr.test`

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/members', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  // ─── Create (role = Driver) ───────────────────────────────────────────────
  await page.getByRole('button', { name: 'Ajouter un membre' }).click()
  await page.getByLabel('Prénom').fill('E2E')
  await page.getByLabel('Nom', { exact: true }).fill('Lifecycle')
  await page.getByLabel('E-mail').fill(email)
  await page.locator('[data-test="member-role-select"]').click()
  await page.getByRole('option', { name: 'Driver' }).first().click()
  await clickAndExpectApi(page, { urlIncludes: '/api/company/members', method: 'POST' },
    () => page.getByRole('button', { name: 'Ajouter', exact: true }).click())

  await page.goto('/company/members', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const row = page.locator('tr', { hasText: email })
  await expect(row).toBeVisible({ timeout: 10000 })
  await expect(row, 'created with Driver role').toContainText('Driver')

  // ─── Owner protection: the owner (Propriétaire) row has no delete action ────
  const ownerRow = page.locator('tr', { hasText: 'owner@leezr.test' })
  await expect(ownerRow.locator('[data-test="member-delete"]')).toHaveCount(0)

  // ─── Edit role → Manager ────────────────────────────────────────────────────
  await row.locator('[data-test="member-edit"]').click()
  await page.waitForURL(/\/company\/members\/\d+/, { timeout: 10000 })
  await page.waitForTimeout(1200)
  await page.locator('[data-test="member-profile-role"]').click()
  await page.getByRole('option', { name: 'Manager', exact: true }).first().click()
  const detailUrl = page.url()
  const memberId = detailUrl.match(/members\/(\d+)/)[1]
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
