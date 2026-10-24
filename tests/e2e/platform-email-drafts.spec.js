// @ts-check
// ADR-605 (LOT 4) — draft autosave with debounce + visible indicator; draft persists server-side.
import { expect, test } from '@playwright/test'
import { loginPlatform, apiRequest } from './helpers'

test('composing autosaves a draft and shows the saved indicator', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')

  // Baseline draft count via API (server-side truth).
  const before = (await apiRequest(page, { method: 'GET', path: '/api/platform/email/inbox?folder=draft' })).json
  const beforeCount = (before?.threads?.length ?? before?.data?.length ?? 0)

  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })
  await page.locator('[data-test="email-compose-btn"]').click()
  await expect(page.locator('[data-test="email-compose"]')).toBeVisible({ timeout: 8000 })

  await page.locator('[data-test="compose-to"] input').fill('draft-test@acme.com')
  await page.locator('[data-test="compose-subject"] input').fill('Autosaved subject')
  const body = page.locator('[data-test="compose-body"] .ProseMirror')
  await body.click()
  await body.type('This should autosave as a draft.')

  // The indicator reaches the "saved" state after the debounce.
  await expect(page.locator('[data-test="compose-draft-status"]')).toContainText(/Draft saved|Brouillon enregistré/i, { timeout: 8000 })

  // Server-side: a new draft exists.
  const after = (await apiRequest(page, { method: 'GET', path: '/api/platform/email/inbox?folder=draft' })).json
  const afterCount = (after?.threads?.length ?? after?.data?.length ?? 0)
  expect(afterCount).toBeGreaterThan(beforeCount)
})
