// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'

// Real Company CRUD against the isolated local base. UI action → API → persistence (reload).
test.describe('Company CRUD (owner)', () => {
  test.beforeEach(async ({ page }) => { await loginCompany(page) })

  test('create a member → persists and appears after reload', async ({ page }) => {
    await page.goto('/company/members', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1000)

    const email = `e2e.member.${Date.now()}@leezr.test`

    await page.getByRole('button', { name: 'Ajouter un membre' }).click()
    await page.getByLabel('Prénom').fill('E2E')
    await page.getByLabel('Nom', { exact: true }).fill('Member')
    await page.getByLabel('E-mail').fill(email)

    // Role is required — pick the first option in the Vuetify VSelect labelled "Rôle".
    const roleSelect = page.locator('[data-test="member-role-select"]')
    await roleSelect.click()
    const opt = page.locator('.v-overlay-container .v-list-item').first()
    await opt.waitFor({ state: 'visible', timeout: 6000 })
    await opt.click()

    await clickAndExpectApi(page, { urlIncludes: '/api/company/members', method: 'POST' },
      () => page.getByRole('button', { name: 'Ajouter', exact: true }).click())

    // Persistence: reload and find the new member.
    await page.goto('/company/members', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1200)
    await expect(page.getByText(email)).toBeVisible({ timeout: 10000 })
  })
})
