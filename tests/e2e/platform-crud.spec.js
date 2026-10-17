// @ts-check
import { expect, test } from '@playwright/test'
import { loginPlatform, clickAndExpectApi } from './helpers'

// Real Platform CRUD (admin). Company suspend → reactivate via the UI, with persistence
// proven by reload (reactivate-button count reflects the status change in the DB).
test.describe('Platform CRUD (admin)', () => {
  test.beforeEach(async ({ page }) => { await loginPlatform(page) })

  test('suspend then reactivate a company persists across reload', async ({ page }) => {
    await page.goto('/platform/companies', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1500)

    const reactivateBtns = () => page.locator('.v-btn:has(.tabler-check)')
    const suspendBtns = () => page.locator('.v-btn:has(.tabler-ban)')

    await expect(suspendBtns().first()).toBeVisible({ timeout: 10000 }) // at least one active company
    const before = await reactivateBtns().count()

    // SUSPEND the first active company.
    await clickAndExpectApi(page, { urlIncludes: "/suspend", method: "PUT" }, async () => {
      await suspendBtns().first().click()
      await page.getByRole("button", { name: "Confirm", exact: true }).click()
    })

    // Persistence: reload → one more suspended company (reactivate button).
    await page.goto('/platform/companies', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1500)
    await expect.poll(() => reactivateBtns().count(), { timeout: 10000 }).toBe(before + 1)

    // REACTIVATE to restore the isolated base.
    await clickAndExpectApi(page, { urlIncludes: '/reactivate', method: 'PUT' },
      () => reactivateBtns().first().click())
    await page.goto('/platform/companies', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1500)
    await expect.poll(() => reactivateBtns().count(), { timeout: 10000 }).toBe(before)
  })
})
