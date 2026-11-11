// @ts-check
// ADR-758 (DMS M1.1) — captures : dossier documentaire d'un Employee SANS compte (sujet employee),
// + rappel des surfaces. Connexion owner.
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dms-m11'
const ACCOUNTLESS_REF = 'employee-1728'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1500)
}

test('DMS M1.1 — dossier Employee sans compte (light + dark)', async ({ page }) => {
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1600 })

  // Fiche de l'employé SANS compte → onglet Documents
  await page.goto(`/company/people/${ACCOUNTLESS_REF}`)
  await settle(page)
  try {
    await page.getByRole('tab', { name: /documents/i }).click({ timeout: 4000 })
    await settle(page)
  } catch { /* onglet absent */ }
  await page.screenshot({ path: `${DIR}/01-accountless-employee-folder-light.png`, fullPage: true })

  // Mobile
  await page.setViewportSize({ width: 390, height: 1400 })
  await settle(page)
  await page.screenshot({ path: `${DIR}/02-accountless-employee-folder-mobile.png`, fullPage: true })

  // Dark
  await page.setViewportSize({ width: 1440, height: 1600 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto(`/company/people/${ACCOUNTLESS_REF}`)
  await settle(page)
  try {
    await page.getByRole('tab', { name: /documents/i }).click({ timeout: 4000 })
    await settle(page)
  } catch { /* */ }
  await page.screenshot({ path: `${DIR}/03-accountless-employee-folder-dark.png`, fullPage: true })
})
