// SP1a — recette Smart Planning : grille pro + besoins de personnel (coverage) + modèles enrichis.
// Écritures RÉELLEMENT persistées (POST 201 observés), pas de maquette. Desktop (la grille est desktop-only).
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

test('coverage : création persistée d\'un besoin de personnel + ligne de synthèse', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1100 })

  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)

  // Onglet « Planning » (grille desktop employés × jours).
  await page.getByRole('tab', { name: /Planning/i }).click()
  await page.waitForTimeout(500)

  // Ouvre le gestionnaire « Besoins de personnel ».
  await page.getByRole('button', { name: /Besoins de personnel/i }).click()
  await page.waitForTimeout(500)

  // Renseigne un besoin : lundi, 3 personnes, priorité haute.
  await page.getByLabel('Jour').click()
  await page.getByRole('option', { name: 'Lundi' }).click()
  await page.getByLabel('Effectif requis').fill('3')

  // La création est RÉELLEMENT persistée (POST 201).
  const [covResp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/coverage$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.getByRole('button', { name: /^Ajouter$/ }).click(),
  ])
  expect(covResp.status()).toBe(201)

  // Le besoin apparaît dans la liste du drawer.
  await expect(page.getByText('Lundi', { exact: false }).first()).toBeVisible()
})

test('template enrichi : code + type d\'activité persistés', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1100 })

  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)

  await page.getByRole('tab', { name: /Modèles/i }).click()
  await page.waitForTimeout(500)

  // Ouvre le drawer de création de modèle.
  await page.getByRole('button', { name: /Nouveau modèle|New template/i }).first().click()
  await page.waitForTimeout(400)

  const uniq = `RT-${Date.now().toString().slice(-5)}`
  await page.getByLabel('Nom du modèle').fill(`Tournée ${uniq}`)
  await page.getByLabel('Code').fill(uniq)
  await page.getByLabel("Type d'activité").fill('tournee')
  await page.getByLabel('Heure de début').fill('08:45')
  await page.getByLabel('Heure de fin').fill('17:30')

  const [tplResp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/templates$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.getByRole('button', { name: /^Créer$/ }).click(),
  ])
  expect(tplResp.status()).toBe(201)
  const body = await tplResp.json()
  // Les champs enrichis sont persistés côté serveur (pas seulement affichés).
  expect(body.code).toBe(uniq)
  expect(body.activity_type).toBe('tournee')
})
