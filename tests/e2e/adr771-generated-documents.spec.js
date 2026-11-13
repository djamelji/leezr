// @ts-check
// ADR-771 — un contrat généré est classé dans le dossier du collaborateur PAR RÉFÉRENCE (sans
// téléversement), téléchargeable depuis la fiche ; réseau classifié, aucune erreur inattendue.
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany, watchNetwork } from './helpers.js'

test('fiche Collaborateur : les documents générés (contrat compris) sont dans le dossier', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const net = watchNetwork(page)

  await loginCompany(page, 'owner@leezr.test')
  const people = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  let target = null
  for (const row of people.json.data.filter(r => r.employee_id)) {
    const file = await apiRequest(page, { path: `/api/workforce/documents/by-employee/${row.employee_id}` })
    if (file.status === 200 && file.json.documents.length) { target = { row, docs: file.json.documents }; break }
  }
  test.skip(!target, 'aucun document généré sur cette base')

  await page.goto(`/company/people/${target.row.person_ref}?tab=documents`, { waitUntil: 'domcontentloaded' })
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
  await expect(page.locator('[data-test="employee-generated-documents"]')).toBeVisible({ timeout: 20000 })
  const first = target.docs[0]
  await expect(page.locator(`[data-test="generated-document-${first.id}"]`)).toContainText(first.label)

  // Téléchargement réel de l'original depuis la fiche.
  if (first.can_download) {
    const [res] = await Promise.all([
      page.waitForResponse(r => r.url().includes(`/api/workforce/documents/${first.id}/download`), { timeout: 20000 }),
      page.locator(`[data-test="generated-document-${first.id}"] button`).last().click(),
    ])
    expect(res.status()).toBe(200)
  }
  await page.screenshot({ path: 'tests/e2e/__screens__/adr771/01-file-generated-documents.png', fullPage: true })
  net.assertClean('dossier des documents générés')
})
