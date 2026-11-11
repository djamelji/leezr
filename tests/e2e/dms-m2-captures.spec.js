// @ts-check
// ADR-759 (DMS M2) — captures : centre de demandes Company + self-service « Mes documents »
// (échéance, message, motif de refus). Connexion owner.
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dms-m2'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1600)
}

test('DMS M2 — centre de demandes + self-service (light + dark + mobile)', async ({ page }) => {
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1700 })

  // 1. Centre de demandes Company
  await page.goto('/company/documents/requests')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-request-center-light.png`, fullPage: true })

  // 2. Self-service « Mes documents » (échéance/message/motif inline)
  await page.goto('/account-settings/documents')
  await settle(page)
  await page.screenshot({ path: `${DIR}/02-my-documents-light.png`, fullPage: true })

  // Mobile
  await page.setViewportSize({ width: 390, height: 1500 })
  await settle(page)
  await page.screenshot({ path: `${DIR}/03-my-documents-mobile.png`, fullPage: true })

  // Dark
  await page.setViewportSize({ width: 1440, height: 1700 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/company/documents/requests')
  await settle(page)
  await page.screenshot({ path: `${DIR}/01-request-center-dark.png`, fullPage: true })
})
