// Refonte Expérience Planning — M6.B (ADR-730) : calendrier hebdomadaire D1, depuis la VRAIE interface.
// Couvre les NOUVELLES capacités (regroupement équipe, recherche, densité, thèmes) + mesures de rendu.
// Sélecteurs robustes (pas de .first() arbitraire sur données ambiguës). La non-régression des parcours
// existants (DnD, multi-sélection, copie, undo, publication, sureffectif) est prouvée par les lots
// workforce-lot4a/lot4b1/lot4b2/lot4b3b/grid-refonte (verts) + PHPUnit.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

async function gotoGrid(page, w = 1600) {
  await page.setViewportSize({ width: w, height: 1100 })
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await expect(page.locator('.planning-grid').first()).toBeVisible()
  await page.waitForTimeout(1000)
}

test('M6.B A — regroupement par équipe : en-têtes repliables', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoGrid(page)
  await page.locator('[data-test="group-team"]').click()
  await page.waitForTimeout(500)
  const headers = page.locator('[data-test="group-header"]')
  const n = await headers.count()
  test.skip(n === 0, 'Aucune équipe définie sur ce tenant — regroupement plat (fallback attendu).')

  const before = await page.locator('.plan-emp-row:visible').count()
  expect(before).toBeGreaterThan(0)
  // Replier le 1er groupe → moins de lignes visibles.
  await headers.first().click()
  await page.waitForTimeout(400)
  const after = await page.locator('.plan-emp-row:visible').count()
  expect(after).toBeLessThan(before)
  // Ré-expansion → retour à l'état initial.
  await headers.first().click()
  await page.waitForTimeout(400)
  expect(await page.locator('.plan-emp-row:visible').count()).toBe(before)
})

test('M6.B B — recherche collaborateur : filtre les lignes de la grille', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoGrid(page)
  await page.locator('[data-test="group-none"]').click() // liste plate → comptage direct
  await page.waitForTimeout(400)
  const all = await page.locator('.plan-emp-row').count()
  expect(all).toBeGreaterThan(1)

  const fullName = ((await page.locator('.plan-emp-row .plan-emp-name').first().textContent()) || '').trim()
  const token = fullName.split(' ')[0]
  test.skip(!token, 'Pas de nom lisible (base E2E).')
  await page.locator('[data-test="grid-search"] input').fill(token)
  await page.waitForTimeout(600)
  const filtered = await page.locator('.plan-emp-row').count()
  expect(filtered).toBeGreaterThan(0)
  expect(filtered).toBeLessThanOrEqual(all)
  // Toutes les lignes visibles contiennent le terme recherché.
  const names = await page.locator('.plan-emp-row .plan-emp-name').allTextContents()
  for (const nm of names) expect(nm.toLowerCase()).toContain(token.toLowerCase())
  // Effacer → retour au total.
  await page.locator('[data-test="grid-search"] input').fill('')
  await page.waitForTimeout(500)
  expect(await page.locator('.plan-emp-row').count()).toBe(all)
})

test('M6.B C — densité : bascule compacte / confortable', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoGrid(page)
  const grid = page.locator('.planning-grid').first()
  const was = await grid.evaluate(el => el.classList.contains('planning-grid--compact'))
  await page.locator('[data-test="density-toggle"]').click()
  await page.waitForTimeout(300)
  expect(await grid.evaluate(el => el.classList.contains('planning-grid--compact'))).toBe(!was)
  // Persistance (cookie) après rechargement.
  await page.reload({ waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await expect(grid).toBeVisible()
  expect(await grid.evaluate(el => el.classList.contains('planning-grid--compact'))).toBe(!was)
})

test('M6.B D — thèmes clair & sombre : rendu sans erreur JS', async ({ page }) => {
  test.setTimeout(90000)
  const errors = []
  page.on('pageerror', e => errors.push(String(e)))
  await loginCompany(page)
  await gotoGrid(page)
  await expect(page.locator('.planning-grid').first()).toBeVisible()
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'))
  await page.waitForTimeout(600)
  await expect(page.locator('.planning-grid').first()).toBeVisible()
  await expect(page.locator('.planning-day-head').first()).toBeVisible()
  expect(errors, 'aucune erreur JS en clair/sombre').toEqual([])
})

test('M6.B PERF — latences de rendu sur données réelles (log)', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  const navStart = Date.now()
  await gotoGrid(page)
  const loadMs = Date.now() - navStart
  const rows = await page.locator('.plan-emp-row').count()
  const cells = await page.locator('[data-droppable="1"]').count()

  const timeIt = async fn => { const t = Date.now(); await fn(); return Date.now() - t }

  // Changement de semaine (re-fetch + re-render).
  const weekMs = await timeIt(async () => {
    await page.locator('.plan-toolbar .v-btn').first().click() // prevWeek (chevron gauche)
    await page.waitForTimeout(50)
    await expect(page.locator('.planning-grid').first()).toBeVisible()
  })
  // Recherche (filtre client pur).
  const searchMs = await timeIt(async () => {
    await page.locator('[data-test="grid-search"] input').fill('a')
    await page.waitForTimeout(50)
  })
  await page.locator('[data-test="grid-search"] input').fill('')
  // Bascule densité (re-render CSS pur).
  const densityMs = await timeIt(async () => {
    await page.locator('[data-test="density-toggle"]').click()
    await page.waitForTimeout(50)
  })

  console.log(`M6B_PERF rows=${rows} cells=${cells} loadMs=${loadMs} weekChangeMs=${weekMs} searchMs=${searchMs} densityMs=${densityMs}`)
  // Garde-fou souple : le rendu reste interactif (pas de blocage) sur le volume réel.
  expect(rows).toBeGreaterThan(0)
  expect(weekMs).toBeLessThan(15000)
})
