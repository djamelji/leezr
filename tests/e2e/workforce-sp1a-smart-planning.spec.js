// SP1a — recette Smart Planning : grille pro + besoins de personnel (coverage) + modèles enrichis.
// Écritures RÉELLEMENT persistées (POST 201 observés), pas de maquette. Le planning vit dans l'espace
// unifié « Temps de travail » (onglet Planning) ; la grille hebdo est desktop-only.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

test('coverage : création persistée d\'un besoin de personnel', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1100 })

  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(500)

  await page.getByRole('button', { name: /Besoins de personnel/i }).click()
  await page.waitForTimeout(500)

  // Scope au drawer « Besoins de personnel » (par son titre) pour éviter les autres drawers.
  const drawer = page.locator('.v-navigation-drawer', { has: page.getByRole('heading', { name: 'Besoins de personnel' }) })
  // 1er combobox du drawer = « Jour » (puis Département, Priorité).
  await drawer.getByRole('combobox').first().click()
  await page.getByRole('option', { name: 'Lundi' }).click()
  await drawer.getByLabel('Effectif requis').fill('3')

  // La création est RÉELLEMENT persistée (POST 201).
  const [covResp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/coverage$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    drawer.locator('[data-test="coverage-submit"]').click(),
  ])
  expect(covResp.status()).toBe(201)
  await expect(drawer.getByText('Lundi', { exact: false }).first()).toBeVisible()
})

test('template enrichi : code + type d\'activité persistés', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1100 })

  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="plan-view-templates"]').click()
  await page.waitForTimeout(500)

  await page.getByRole('button', { name: /Nouveau modèle|New template/i }).first().click()
  await page.waitForTimeout(400)

  const drawer = page.locator('.v-navigation-drawer--active')
  const uniq = `RT${Date.now().toString().slice(-5)}`
  await drawer.getByLabel('Nom du modèle').fill(`Tournée ${uniq}`)
  await drawer.getByLabel('Code', { exact: true }).fill(uniq)
  await drawer.getByLabel("Type d'activité").fill('tournee')
  await drawer.getByLabel('Heure de début').fill('08:45')
  await drawer.getByLabel('Heure de fin').fill('17:30')

  const [tplResp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/templates$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    drawer.locator('[data-test="template-submit"]').click(),
  ])
  expect(tplResp.status()).toBe(201)
  const body = await tplResp.json()
  expect(body.code).toBe(uniq)
  expect(body.activity_type).toBe('tournee')
})
