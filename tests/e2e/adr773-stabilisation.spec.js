// @ts-check
// ADR-773 — surfaces touchées, en LECTURE SEULE (aucune donnée créée) : registre RH (le filtre de
// signature rend exactement l'état affiché, quel que soit l'état), coffre de l'entreprise, fiche.
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany, watchNetwork } from './helpers.js'

test('registre RH : chaque filtre de signature ne rend que l\'état affiché', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const net = watchNetwork(page)

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/documents/hr', { waitUntil: 'domcontentloaded' })
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(3500)
  await page.screenshot({ path: 'tests/e2e/__screens__/adr773/01-registry.png', fullPage: true })

  for (const state of ['pending', 'in_progress', 'signed', 'declined', 'expired', 'cancelled', 'failed']) {
    const res = await apiRequest(page, { path: `/api/workforce/documents?signature_status=${state}&per_page=50` })
    expect(res.status, state).toBe(200)
    const rows = res.json.data ?? []
    expect(rows.every(r => r.signature_status === state), `filtre ${state}`).toBe(true)
  }
  const stats = await apiRequest(page, { path: '/api/workforce/documents/statistics' })
  expect(stats.status).toBe(200)

  await page.goto('/company/documents/vault', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(5000)
  await page.screenshot({ path: 'tests/e2e/__screens__/adr773/02-vault.png', fullPage: true })
  net.assertClean('ADR-773')
})
