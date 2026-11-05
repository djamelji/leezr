// Lot 2 — réserve ADR-714 §9 : drawer scrollable même avec erreur de validation (hauteur accrue) et sur
// mobile ; sauvegarde réelle depuis le bas du drawer. Parcours UI réels.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

test('drawer : erreur de validation (contenu plus haut) → scroll + action du bas accessibles', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1280, height: 720 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(900)
  await page.getByRole('button', { name: /Besoins de personnel/i }).click()
  await page.waitForTimeout(500)
  const drawer = page.locator('.v-navigation-drawer.wf-scroll-drawer', { has: page.getByRole('heading', { name: 'Besoins de personnel' }) })

  // Scroll jusqu'au bas, soumettre SANS jour → erreur de validation (toast) ; le bouton reste accessible.
  await drawer.locator('.v-navigation-drawer__content').evaluate(c => c.scrollTo(0, c.scrollHeight))
  await page.waitForTimeout(200)
  await expect(drawer.locator('[data-test="coverage-submit"]')).toBeVisible()
  await drawer.locator('[data-test="coverage-submit"]').click() // jour manquant → pas de POST, message
  await page.waitForTimeout(300)

  // Puis saisir un jour + enregistrer réellement depuis le bas du drawer.
  await drawer.getByRole('combobox').first().click()
  await page.getByRole('option', { name: 'Mercredi' }).click()
  await drawer.locator('.v-navigation-drawer__content').evaluate(c => c.scrollTo(0, c.scrollHeight))
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/coverage$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    drawer.locator('[data-test="coverage-submit"]').click(),
  ])
  expect(resp.status()).toBe(201)
})

test('drawer mobile 390 : scroll et action accessibles', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.setViewportSize({ width: 390, height: 844 })
  // Le drawer couverture n'est atteignable que depuis la grille (desktop) ; on vérifie ici le drawer de
  // MODÈLE, accessible sur mobile via l'onglet Modèles — même classe de correction partagée.
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="plan-view-templates"]').click()
  await page.waitForTimeout(600)
  await page.getByRole('button', { name: /Nouveau modèle|New template/i }).first().click()
  await page.waitForTimeout(400)
  const drawer = page.locator('.v-navigation-drawer.wf-scroll-drawer').filter({ has: page.locator('[data-test="template-submit"]') })
  const ov = await drawer.evaluate(el => getComputedStyle(el.querySelector('.v-navigation-drawer__content')).overflowY)
  expect(ov).toBe('auto')
  await drawer.locator('.v-navigation-drawer__content').evaluate(c => c.scrollTo(0, c.scrollHeight))
  await page.waitForTimeout(200)
  await expect(drawer.locator('[data-test="template-submit"]')).toBeVisible()
})
