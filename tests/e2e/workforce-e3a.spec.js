// @ts-check
// E3-A (ADR-692) — refonte annuaire + fiche collaborateur. Vérifie que la refonte ergonomique
// fonctionne : action primaire + overflow, recherche + filtres repliables, états, fiche consolidée.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

test('annuaire refondu : en-tête, compteur, recherche, filtres repliables', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await page.waitForResponse(r => r.url().includes('/company/people') && r.request().method() === 'GET', { timeout: 15000 }).catch(() => {})

  // ADR-864 : l'ancienne liste RH est Personnes — tableau, « Ajouter », outils RH (⋯), filtres RH visibles.
  await expect(page).toHaveURL(/\/company\/people(\?|$)/, { timeout: 15000 })
  await expect(page.locator('[data-test="people-table"]')).toBeVisible({ timeout: 10000 })
  await expect(page.locator('[data-test="people-add"]')).toBeVisible()
  await expect(page.locator('[data-test="emp-more"]')).toBeVisible()
  await expect(page.locator('[data-test="people-filter-employment_status"]')).toBeVisible()

  // Recherche fonctionnelle (déclenche un fetch).
  const [resp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('/company/people') && r.url().includes('search='), { timeout: 10000 }).catch(() => null),
    page.locator('[data-test="people-search"] input').fill('Thomas'),
  ])
  expect(resp, 'la recherche déclenche un appel serveur').not.toBeNull()

  // Drawer de création s'ouvre — ADR-802 : tiroir unique d'Équipe.
  await page.locator('[data-test="people-add"]').click()
  await expect(page.locator('[data-test="person-add-first-name"]')).toBeVisible({ timeout: 8000 })

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('fiche refondue : en-tête consolidé, actions, salaire visible pour owner', async ({ page }) => {
  // ADR-768 / ADR-803.1 : l'ancienne fiche salarié redirige vers la fiche personne d'Équipe — on teste la vraie fiche.
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await expect(page).toHaveURL(/\/company\/people\/employee-1/, { timeout: 15000 }) // lien historique préservé
  await expect(page.locator('[data-test="person-header"]')).toBeVisible({ timeout: 15000 })

  // Actions de la fiche : « Gérer les accès » disponible pour l'owner (bouton direct ou menu selon la largeur).
  const menu = page.locator('[data-test="person-actions-menu"]')
  if (await menu.count()) await menu.first().click()
  await expect(page.locator('[data-test="person-action-manage-access"]').first()).toBeVisible({ timeout: 8000 })
  await page.keyboard.press('Escape')

  // Owner a workforce.compensation_read → la rémunération est présente dans la synthèse.
  await expect(page.getByText(/\d[\d\s.,]*\s?€/).first()).toBeVisible({ timeout: 8000 })

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
