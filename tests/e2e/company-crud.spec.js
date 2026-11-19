// @ts-check
import { expect, test } from '@playwright/test'
import { apiRequest, clickAndExpectApi, loginCompany } from './helpers'
import { createRun } from './run-ledger.js'

// Real Company CRUD against the isolated clone. UI action → API → persistence (reload).
// ADR-831 (D-21 bis) — the « Ajouter un membre » button of /company/members was removed by ADR-802 (the list is
// absorbed by /company/people) : the member is now created through the single « Ajouter une personne » drawer,
// with access and without an employee record. Same assertions : real POST, visible after reload.
const run = createRun('company-crud')

test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })

test.describe('Company CRUD (owner)', () => {
  test.beforeEach(async ({ page }) => { await loginCompany(page) })

  test('create a member → persists and appears after reload', async ({ page }) => {
    run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)
    await page.goto('/company/people', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1000)

    const email = run.email('member')

    await page.locator('[data-test="people-add"]').click()
    await page.locator('[data-test="person-add-first-name"] input').fill('E2E')
    await page.locator('[data-test="person-add-last-name"] input').fill('Member')
    await page.locator('[data-test="person-add-access-yes"] input').check()
    await page.locator('[data-test="person-add-email"] input').fill(email)
    await page.locator('[data-test="person-add-staff"] input').uncheck()

    // Role is required — pick the first option.
    await page.locator('[data-test="person-add-role"]').click()
    const opt = page.locator('.v-overlay-container .v-list-item').first()
    await opt.waitFor({ state: 'visible', timeout: 6000 })
    await opt.click()

    const created = await clickAndExpectApi(page, { urlIncludes: '/api/company/members', method: 'POST' },
      () => page.locator('[data-test="person-add-submit"]').click())
    const body = await created.json()
    run.track('membership', body.member.id)
    run.track('user', body.member.user.id)

    // Persistence: reload the list and find the new member.
    await page.goto('/company/people', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1200)
    await page.getByPlaceholder('Rechercher un collaborateur…').fill(email)
    await expect(page.locator('tr', { hasText: email })).toBeVisible({ timeout: 10000 })
  })
})
