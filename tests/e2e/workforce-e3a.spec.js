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
  await page.waitForResponse(r => r.url().includes('/workforce/employees') && r.request().method() === 'GET', { timeout: 15000 }).catch(() => {})

  // Compteur total visible dans le titre.
  await expect(page.locator('[data-test="emp-count"]')).toBeVisible({ timeout: 10000 })

  // Action primaire + overflow (export/import regroupés).
  await expect(page.locator('[data-test="emp-add"]')).toBeVisible()
  await expect(page.locator('[data-test="emp-more"]')).toBeVisible()

  // Filtres avancés repliés par défaut, révélés au clic (« Département » n'existe que dans les filtres).
  await expect(page.locator('[data-test="emp-filters-toggle"]')).toBeVisible()
  await expect(page.getByText('Département', { exact: true })).toHaveCount(0)
  await page.locator('[data-test="emp-filters-toggle"]').click()
  await page.waitForTimeout(700)
  await expect(page.getByText('Département', { exact: true }).first()).toBeVisible()

  // Recherche fonctionnelle (déclenche un fetch).
  const [resp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('/workforce/employees') && r.url().includes('search='), { timeout: 10000 }).catch(() => null),
    page.locator('[data-test="emp-search"] input').fill('Thomas'),
  ])
  expect(resp, 'la recherche déclenche un appel serveur').not.toBeNull()

  // Drawer de création s'ouvre.
  await page.locator('[data-test="emp-add"]').click()
  await expect(page.locator('[data-test="emp-first-name"]')).toBeVisible({ timeout: 8000 })

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('fiche refondue : en-tête consolidé, overflow, salaire visible pour owner', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await page.waitForResponse(r => r.url().includes('/file') && r.request().method() === 'GET', { timeout: 15000 }).catch(() => {})

  // Action primaire « Modifier » (SmartActionBar). « Gérer les accès » est accessible soit en bouton
  // direct, soit via l'overflow selon la largeur réelle du conteneur header (comportement adaptatif).
  await expect(page.locator('[data-test="emp-edit"]')).toBeVisible({ timeout: 10000 })
  const overflow = page.locator('[data-test="sab-overflow"]')
  if (await overflow.count()) {
    await overflow.click()
    await page.waitForTimeout(400)
  }
  await expect(page.locator('[data-test="emp-manage-access"]')).toBeVisible()
  await page.keyboard.press('Escape')

  // Owner a workforce.compensation_read → le salaire (MoneyDisplay) est présent dans la synthèse.
  await expect(page.getByText(/\d[\d\s.,]*\s?€/).first()).toBeVisible({ timeout: 8000 })

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})
