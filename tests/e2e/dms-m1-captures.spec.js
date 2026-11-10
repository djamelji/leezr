// @ts-check
// ADR-757 (DMS M1) — captures des 3 surfaces documentaires : coffre Company, dossier
// Collaborateur (fiche people), self-service « Mes documents ». Connexion owner (toutes perms).
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dms-m1'
const PERSON_REF = 'user-1'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1500)
}

test('DMS M1 — trois surfaces documentaires (light + dark)', async ({ page }) => {
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1800 })

  // 1. Coffre Company
  await page.goto('/company/documents/vault')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-company-vault-light.png`, fullPage: true })

  // 2. Dossier Collaborateur (onglet Documents de la fiche)
  await page.goto(`/company/people/${PERSON_REF}`)
  await settle(page)
  try {
    await page.getByRole('tab', { name: /documents/i }).click({ timeout: 4000 })
    await settle(page)
  } catch { /* onglet absent (pas de droit/doc) — capture l'état courant */ }
  await page.screenshot({ path: `${DIR}/02-collaborator-folder-light.png`, fullPage: true })

  // 3. Self-service « Mes documents »
  await page.goto('/account-settings/documents')
  await settle(page)
  await page.screenshot({ path: `${DIR}/03-my-documents-light.png`, fullPage: true })

  // Dark mode
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/company/documents/vault')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-company-vault-dark.png`, fullPage: true })

  await page.goto('/account-settings/documents')
  await settle(page)
  await page.screenshot({ path: `${DIR}/03-my-documents-dark.png`, fullPage: true })
})
