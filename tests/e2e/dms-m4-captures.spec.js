// @ts-check
// ADR-761 (DMS M4) — captures : campagnes documentaires (liste + suivi) + création. Connexion owner.
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dms-m4'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1600)
}

test('DMS M4 — campagnes (liste + suivi + création) light/dark/mobile', async ({ page }) => {
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1600 })

  // 1. Onglet Campagnes (liste)
  await page.goto('/company/documents/campaigns')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-campaigns-list-light.png`, fullPage: true })

  // 2. Suivi de campagne
  try {
    await page.getByRole('button', { name: /suivre|track/i }).first().click({ timeout: 4000 })
    await settle(page)
  } catch { /* */ }
  await page.screenshot({ path: `${DIR}/02-campaign-tracking-light.png`, fullPage: true })

  // 3. Création (drawer)
  await page.goto('/company/documents/campaigns')
  await settle(page)
  try {
    await page.getByRole('button', { name: /nouvelle campagne|new campaign/i }).first().click({ timeout: 4000 })
    await settle(page)
  } catch { /* */ }
  await page.screenshot({ path: `${DIR}/03-campaign-create-light.png`, fullPage: true })

  // Mobile + dark (liste)
  await page.setViewportSize({ width: 390, height: 1400 })
  await page.goto('/company/documents/campaigns')
  await settle(page)
  await page.screenshot({ path: `${DIR}/04-campaigns-mobile.png`, fullPage: true })

  await page.setViewportSize({ width: 1440, height: 1600 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/company/documents/campaigns')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-campaigns-list-dark.png`, fullPage: true })
})
