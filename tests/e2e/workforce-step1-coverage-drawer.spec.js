// Étape 1 — recette E2E complète du drawer « Besoins de personnel » : besoin ponctuel ET récurrent,
// catégorie, édition, suppression, fermeture/réouverture, persistance après rechargement. Parcours UI réels.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

async function openDrawer(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1000)
  await page.getByRole('button', { name: /Besoins de personnel/i }).click()
  await page.waitForTimeout(600)

  return page.locator('.v-navigation-drawer', { has: page.getByRole('heading', { name: 'Besoins de personnel' }) })
}

test('drawer besoins : ponctuel + récurrent + catégorie + édition + suppression + persistance', async ({ page }) => {
  test.setTimeout(180000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1512, height: 982 })
  const drawer = await openDrawer(page)
  const stamp = Date.now().toString().slice(-5)

  // 1) Créer une CATÉGORIE.
  await drawer.getByPlaceholder(/Nom de catégorie|Category name/i).fill(`Cat ${stamp}`)
  await drawer.getByPlaceholder(/^Code$/).fill(`C${stamp}`)
  const [catResp] = await Promise.all([
    page.waitForResponse(r => /\/coverage-categories$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    drawer.locator('[data-test="cat-add"]').click(),
  ])
  expect(catResp.status()).toBe(201)

  // 2) Créer un besoin PONCTUEL (date) — exigence clé, impossible avant Étape 1.
  await drawer.locator('[data-test="cov-mode-ponctuel"]').click()
  await drawer.locator('[data-test="cov-date"] input').fill('2027-06-15')
  await drawer.getByLabel(/Effectif requis|Required headcount/i).fill('5')
  const [p1] = await Promise.all([
    page.waitForResponse(r => /\/planning\/coverage$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    drawer.locator('[data-test="coverage-submit"]').click(),
  ])
  expect(p1.status()).toBe(201)

  // 3) Créer un besoin RÉCURRENT (jour de semaine).
  await drawer.locator('[data-test="cov-mode-recurrent"]').click()
  await drawer.locator('[data-test="cov-weekday"]').click()
  await page.getByRole('option', { name: 'Mardi' }).click()
  await drawer.getByLabel(/Effectif requis|Required headcount/i).fill('8')
  const [p2] = await Promise.all([
    page.waitForResponse(r => /\/planning\/coverage$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    drawer.locator('[data-test="coverage-submit"]').click(),
  ])
  expect(p2.status()).toBe(201)

  const countAfterCreate = await drawer.locator('[data-test="cov-req-item"]').count()
  expect(countAfterCreate).toBeGreaterThanOrEqual(2)

  // 4) ÉDITION : modifier l'effectif du premier besoin.
  await drawer.locator('[data-test="cov-req-item"]').first().locator('button').first().click()
  await page.waitForTimeout(300)
  await drawer.getByLabel(/Effectif requis|Required headcount/i).fill('12')
  const [putResp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/coverage\/\d+$/.test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 }),
    drawer.locator('[data-test="coverage-submit"]').click(),
  ])
  expect(putResp.status()).toBe(200)

  // 5) SUPPRESSION du dernier besoin créé.
  const before = await drawer.locator('[data-test="cov-req-item"]').count()
  await Promise.all([
    page.waitForResponse(r => /\/planning\/coverage\/\d+$/.test(r.url()) && r.request().method() === 'DELETE', { timeout: 20000 }),
    drawer.locator('[data-test="cov-req-item"]').last().locator('button').last().click(),
  ])
  await page.waitForTimeout(600)
  expect(await drawer.locator('[data-test="cov-req-item"]').count()).toBe(before - 1)

  // 6) FERMETURE / RÉOUVERTURE : la liste persiste.
  await drawer.getByRole('button').filter({ has: page.locator('.tabler-x') }).first().click()
  await page.waitForTimeout(400)
  await page.getByRole('button', { name: /Besoins de personnel/i }).click()
  await page.waitForTimeout(600)
  expect(await page.locator('[data-test="cov-req-item"]').count()).toBeGreaterThanOrEqual(1)

  // 7) PERSISTANCE après rechargement complet.
  const drawer2 = await openDrawer(page)
  expect(await drawer2.locator('[data-test="cov-req-item"]').count()).toBeGreaterThanOrEqual(1)
})
