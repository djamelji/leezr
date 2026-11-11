// @ts-check
// ADR-760 (DMS M3) — captures : conformité enrichie (états workflow) + timeline des versions
// (viewer self-service). Connexion owner.
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dms-m3'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1600)
}

test('DMS M3 — conformité + timeline versions (light + dark + mobile)', async ({ page }) => {
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1700 })

  // 1. Conformité enrichie (états workflow)
  await page.goto('/company/documents/compliance')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-compliance-light.png`, fullPage: true })

  // 2. Timeline des versions — ouvrir le viewer d'un document de « Mes documents »
  await page.goto('/account-settings/documents')
  await settle(page)
  try {
    // clic sur la première action « œil » (voir) d'une ligne avec upload
    await page.locator('button:has(.tabler-eye), [class*="tabler-eye"]').first().click({ timeout: 4000 })
    await settle(page)
  } catch { /* viewer non ouvert — capture l'état courant */ }
  await page.screenshot({ path: `${DIR}/02-version-timeline-light.png`, fullPage: true })

  // Mobile (conformité)
  await page.setViewportSize({ width: 390, height: 1500 })
  await page.goto('/company/documents/compliance')
  await settle(page)
  await page.screenshot({ path: `${DIR}/03-compliance-mobile.png`, fullPage: true })

  // Dark
  await page.setViewportSize({ width: 1440, height: 1700 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/company/documents/compliance')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-compliance-dark.png`, fullPage: true })
})
