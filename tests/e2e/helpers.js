// @ts-check
// Centralized E2E helpers — real Sanctum SPA flows (no CSRF bypass, no permission bypass).
import { expect } from '@playwright/test'

export async function loginCompany(page, email = 'owner@leezr.test', password = 'password') {
  await page.goto('/login', { waitUntil: 'networkidle' })
  await page.fill('input[type="email"], input[name="email"]', email)
  await page.fill('input[type="password"], input[name="password"]', password)
  await page.keyboard.press('Enter')
  await page.waitForURL(u => !u.pathname.endsWith('/login'), { timeout: 20000 }).catch(() => {})
  await page.waitForTimeout(2500)
}

export async function loginPlatform(page, email = 'admin@leezr.com', password = 'password') {
  await page.goto('/platform/login', { waitUntil: 'networkidle' })
  await page.fill('input[type="email"], input[name="email"]', email)
  await page.fill('input[type="password"], input[name="password"]', password)
  await page.keyboard.press('Enter')
  await page.waitForURL(u => !u.pathname.endsWith('/login'), { timeout: 20000 }).catch(() => {})
  await page.waitForTimeout(2500)
}

// Click an action and assert the triggered API call succeeds (real integration).
export async function clickAndExpectApi(page, { urlIncludes, method }, action) {
  const [resp] = await Promise.all([
    page.waitForResponse(r => r.url().includes(urlIncludes) && r.request().method() === method, { timeout: 20000 }),
    action(),
  ])
  expect(resp.status(), `${method} ${urlIncludes} → ${resp.status()}`).toBeLessThan(400)
  return resp
}

// Pick an option in a Vuetify VSelect identified by its label.
export async function selectVuetifyOption(page, selectLabel, optionIndex = 0) {
  await page.getByLabel(selectLabel, { exact: false }).first().click()
  const opts = page.locator('.v-overlay-container .v-list-item, .v-menu .v-list-item')
  await opts.first().waitFor({ state: 'visible', timeout: 5000 })
  await opts.nth(optionIndex).click()
}
