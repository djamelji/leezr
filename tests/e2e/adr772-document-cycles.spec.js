// @ts-check
// ADR-772 — surfaces touchées : coffre de l'entreprise, registre RH, dossier d'un salarié. Réseau
// classifié : aucune erreur inattendue ; la validation par personRef répond (elle n'existait pas).
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany, watchNetwork } from './helpers.js'

test('coffre entreprise, registre RH et dossier salarié se chargent sans erreur', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const net = watchNetwork(page, {
    expectedRefusals: [
      { status: 404, method: 'PUT', path: /\/documents\/[a-z_]+\/review$/, reason: 'sonde : aucune demande ouverte sur ce document' },
      { status: 422, method: 'PUT', path: /\/documents\/[a-z_]+\/review$/, reason: 'sonde : demande ouverte mais aucun fichier à valider' },
    ],
  })

  await loginCompany(page, 'owner@leezr.test')
  for (const path of ['/company/documents/vault', '/company/documents/hr', '/company/documents/requests']) {
    await page.goto(path, { waitUntil: 'domcontentloaded' })
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
    await page.waitForTimeout(3500)
  }
  await page.screenshot({ path: 'tests/e2e/__screens__/adr772/01-requests.png', fullPage: true })

  // Le coffre expose l'état de la demande portée par l'entreprise.
  const vault = await apiRequest(page, { path: '/api/company/documents' })
  expect(vault.status).toBe(200)
  expect(vault.json.documents.every(d => 'request_status' in d && 'can_review' in d)).toBe(true)

  // Les routes de validation existent (404 = aucune demande ouverte, pas « route inconnue » 405).
  const people = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  const accountless = people.json.data.find(r => r.employee_id && !r.user_id)
  if (accountless) {
    const probe = await apiRequest(page, { method: 'PUT', path: `/api/company/people/${accountless.person_ref}/documents/id_card/review`, body: { status: 'approved' } })
    expect([404, 422]).toContain(probe.status)
    await page.goto(`/company/people/${accountless.person_ref}?tab=documents`, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(4000)
    await expect(page.locator('[data-test="person-header"]')).toBeVisible()
    await page.screenshot({ path: 'tests/e2e/__screens__/adr772/02-accountless-file.png', fullPage: true })
  }
  net.assertClean('cycles documentaires')
})
