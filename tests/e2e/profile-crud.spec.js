// @ts-check
import { expect, test } from '@playwright/test'
// ANO-004 non-regression [NAV]: company profile is saveable from the UI and persists.
test('company profile update persists (NAV→API→DB→UI)', async ({ page }) => {
  await page.goto('/login', { waitUntil: 'networkidle' })
  await page.fill('input[type="email"]', 'owner@leezr.test'); await page.fill('input[type="password"]', 'password')
  await page.keyboard.press('Enter'); await page.waitForTimeout(3500)
  await page.goto('/company/profile/overview', { waitUntil: 'networkidle' })
  const field = page.getByLabel("Nom de l'entreprise")
  await expect(field).not.toHaveValue('', { timeout: 15000 })
  const btn = page.getByRole('button', { name: 'Enregistrer les modifications' })
  const newName = 'Leezr Logistics E2E ' + Date.now()
  await field.fill(newName)
  await expect(btn).toBeEnabled({ timeout: 5000 }) // ANO-004: no longer frozen
  const [resp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('/api/company') && r.request().method() === 'PUT'),
    btn.click(),
  ])
  expect(resp.status()).toBeLessThan(300)
  await page.goto('/company/profile/overview', { waitUntil: 'networkidle' })
  await expect(page.getByLabel("Nom de l'entreprise")).toHaveValue(newName, { timeout: 15000 })
})
