// ADR-786 — recette RBAC (lecture seule, aucune écriture) : ce qu'un profil voit = ce qu'il peut faire.
import { test, expect } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

const settle = page => page.waitForTimeout(2500) // le flux temps réel empêche « networkidle »

test('livreur : ses bulletins sans accès à la paie collective, documents personnels', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('[data-test="me-open-payroll"]')).toHaveCount(0)
  await page.locator('[data-test="me-all-documents"]').click()
  await expect(page).toHaveURL(/\/company\/my-space\/documents/)
  await page.goto('/company/workforce/payroll', { waitUntil: 'domcontentloaded' })
  await expect(page).toHaveURL(/\/company\/403/)
})

test('livreur : la fiche d\'un collègue ne liste pas ses permissions, libellés traduits', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/people/user-2', { waitUntil: 'domcontentloaded' })
  await settle(page)
  // ADR-831 (D-21 bis) — since ADR-777 the file shows server-decided rubrics : the livreur no longer receives the
  // « access » section of a colleague at all (stronger than hiding its permission list). Proven on the API and
  // in the page ; the original assertions below still run on every rubric he does see.
  const file = (await apiRequest(page, { path: '/api/company/people/user-2' })).json
  expect(file.available_sections, 'server exposes no access section to a livreur').not.toContain('access')
  await expect(page.locator('.v-tab', { hasText: 'Accès & autorisations' })).toHaveCount(0)
  for (const tab of await page.locator('.v-tab').all()) {
    await tab.click()
    await page.waitForTimeout(800)
    await expect(page.getByText('Permissions effectives', { exact: false })).toHaveCount(0)
    await expect(page.getByText('members.view', { exact: true })).toHaveCount(0)
  }
  await expect(page.getByText('Permissions effectives', { exact: false })).toHaveCount(0)
  await expect(page.getByText('members.view', { exact: true })).toHaveCount(0)
  // ADR-788 (appliqué) : plus de documents.view pour le livreur → plus d'onglet Documents sur un collègue.
  await expect(page.locator('.v-tab', { hasText: 'Documents' })).toHaveCount(0)
  await expect(page.getByText('documents.state.', { exact: false })).toHaveCount(0)
})

test('consultation : Réglages accessible, organisation en lecture seule', async ({ page }) => {
  await loginCompany(page, 'consultation@leezr.test')
  await page.goto('/company/settings', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page).toHaveURL(/\/company\/settings$/)
  await page.goto('/company/workforce/organization/departments', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('main .v-data-table .tabler-edit:visible, main .v-data-table .tabler-trash:visible')).toHaveCount(0)
})

test('propriétaire : les actions de gestion restent proposées', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/organization/departments', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('main .v-data-table .tabler-edit:visible').first()).toBeVisible()
})
