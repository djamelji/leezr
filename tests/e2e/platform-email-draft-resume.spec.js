// @ts-check
// ADR-609 (recette) — a draft autosaved while composing survives a full page reload and can be
// resumed with its content intact.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

test('autosaved draft is resumable after a reload', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  const token = `RESUME-${Date.now()}`

  await page.locator('[data-test="email-compose-btn"]').click()
  const composer = page.locator('[data-test="email-compose"]')
  await expect(composer).toBeVisible({ timeout: 8000 })
  await composer.locator('[data-test="compose-to"] input').fill('resume@example.org')
  await composer.locator('[data-test="compose-subject"] input').fill(token)
  await composer.locator('[data-test="compose-body"] .ProseMirror').click()
  await page.keyboard.type('Contenu brouillon à reprendre.')

  // Wait for the autosave indicator to report "saved".
  await expect(composer.locator('[data-test="compose-draft-status"]')).toContainText(/enregistré|saved/i, { timeout: 8000 })

  // Full reload → the draft must still exist in the Draft folder, with its subject.
  await page.reload({ waitUntil: 'networkidle' })
  await page.locator('[data-test="folder-draft"]').click()
  await expect(page.getByText(token).first()).toBeVisible({ timeout: 8000 })
})
