// Refonte Expérience Planning — M6.A (ADR-729) : navigation & fondations, depuis la VRAIE interface.
// Prouve : (1) « Planning » = entrée de 1er niveau ouvrant le calendrier autonome ; (2) fin de la
// redondance Planning→Planning (plus d'onglet Planning dans « Temps & présences ») ; (3) redirections
// des anciens liens profonds (deep-link ?tab=planning + legacy settings) → aucun favori/notif cassé.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

test('M6.A — « Planning » est une entrée de nav dédiée qui ouvre directement le calendrier', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })

  const nav = page.locator('.layout-vertical-nav')
  // Deux entrées DISTINCTES dans « Ressources humaines » : Planning (prévisionnel) et Temps (réel).
  const planningLink = nav.locator('a[href$="/company/workforce/planning"]').first()
  const timeLink = nav.locator('a[href$="/company/workforce/time"]').first()
  await expect(planningLink, 'entrée Planning dédiée').toBeVisible({ timeout: 10000 })
  await expect(timeLink, 'entrée Temps & présences distincte').toBeVisible({ timeout: 10000 })

  // Clic « Planning » → page autonome + calendrier affiché immédiatement.
  await planningLink.click()
  await page.waitForURL('**/company/workforce/planning', { timeout: 15000 })
  await expect(page.locator('[data-test="plan-view-calendar"]')).toBeVisible({ timeout: 15000 })
})

test('M6.A — l’ancien deep-link ?tab=planning redirige vers la page Planning autonome', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'domcontentloaded' })
  // Redirection synchrone → on atterrit sur /planning (favoris & notifications préservés).
  await page.waitForURL('**/company/workforce/planning', { timeout: 15000 })
  await expect(page.locator('[data-test="plan-view-calendar"]')).toBeVisible({ timeout: 15000 })
})

test('M6.A — « Temps & présences » ne contient plus d’onglet Planning (dédoublonnage)', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.goto('/company/workforce/time', { waitUntil: 'domcontentloaded' })
  // Reste sur l'espace Temps (pas de redirection pour un owner qui a la lecture pointage).
  await page.waitForTimeout(1500)
  expect(page.url()).toContain('/company/workforce/time')
  // La page planning n'est plus montée ici : aucun contrôle de sous-navigation planning présent.
  await expect(page.locator('[data-test="plan-view-calendar"]')).toHaveCount(0)
  await expect(page.locator('[data-test="plan-view-templates"]')).toHaveCount(0)
})

test('M6.A — redirection legacy settings/planning → page Planning', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.goto('/company/workforce/settings/planning', { waitUntil: 'domcontentloaded' })
  await page.waitForURL('**/company/workforce/planning', { timeout: 15000 })
  await expect(page.locator('[data-test="plan-view-calendar"]')).toBeVisible({ timeout: 15000 })
})
