// Refonte Expérience Planning — M6.C (ADR-731) : parcours de construction unifié, depuis la VRAIE interface.
// A « Préparer ma semaine » (points de départ → parcours existants) · B création rapide (picker cellule) ·
// F navigation clarifiée (Agenda/Semaine/Modèles, plus de « Planning → Planning », bibliothèques accessibles).
// La construction elle-même (semaine type / répétition / bulk / copie) est prouvée par m3/m4/lot4b (verts).
import { test, expect } from '@playwright/test'
import { loginCompany, trackCreatedShifts, cleanupShifts } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

let created
test.beforeEach(({ page }) => { created = trackCreatedShifts(page) })
test.afterEach(async ({ page }) => { await cleanupShifts(page, created) })

async function gotoGrid(page, w = 1500) {
  await page.setViewportSize({ width: w, height: 1000 })
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await expect(page.locator('.planning-grid').first()).toBeVisible()
  await page.waitForTimeout(1000)
}

test('M6.C A — « Préparer ma semaine » oriente vers un parcours existant (semaine type)', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoGrid(page)

  await page.locator('[data-test="prepare-week-open"]').click()
  // 5 points de départ visibles.
  for (const k of ['copyprev', 'copyweek', 'weektpl', 'templates', 'scratch'])
    await expect(page.locator(`[data-test="prepare-${k}"]`)).toBeVisible({ timeout: 8000 })

  // Choisir « Appliquer une semaine type » → ouvre la bibliothèque M3 existante (pas un nouveau moteur).
  await page.locator('[data-test="prepare-weektpl"]').click()
  await expect(page.locator('[data-test="week-new"]')).toBeVisible({ timeout: 10000 })
})

test('M6.C B — création rapide : cellule vide → picker → application d’un modèle', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await gotoGrid(page)

  // Cellule vide (sans shift ni absence).
  const c = await page.$$eval('[data-droppable="1"]', els => {
    const e = els.find(x => !x.querySelector('.plan-shift') && !x.querySelector('.plan-absence'))

    return e ? { emp: e.dataset.emp, date: e.dataset.date } : null
  })
  test.skip(!c, 'Pas de cellule vide (base E2E).')

  await page.locator(`[data-droppable="1"][data-emp="${c.emp}"][data-date="${c.date}"]`).first().click({ position: { x: 60, y: 30 } })
  await expect(page.locator('[data-test="quickpick"]')).toBeVisible({ timeout: 8000 })

  // Au moins un modèle proposé → l'appliquer. Un POST /shifts part (création via le UseCase serveur).
  const tpl = page.locator('[data-test^="quickpick-template-"]').first()
  await expect(tpl).toBeVisible({ timeout: 6000 })
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts$/.test(r.url()) && r.request().method() === 'POST', { timeout: 15000 }),
    tpl.click(),
  ])
  // 201 (créé → tracké pour nettoyage) ou 422 (contrat inactif/sureffectif : base E2E) — le parcours a bien
  // déclenché l'écriture serveur via le picker (pas de créneau codé en dur côté front).
  if (resp.status() === 201) created.add((await resp.json()).id)
  expect([201, 422]).toContain(resp.status())
})

test('M6.C F — navigation clarifiée : Agenda/Semaine/Modèles + bibliothèques accessibles', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)

  // Les 3 modes existent ; le libellé « Semaine » remplace l'ancien « Planning » (fin du Planning→Planning).
  await expect(page.locator('[data-test="plan-view-calendar"]')).toContainText(/Agenda/i)
  await expect(page.locator('[data-test="plan-view-planning"]')).toContainText(/Semaine/i)
  await expect(page.locator('[data-test="plan-view-templates"]')).toContainText(/Modèles|Templates/i)
  // L'action principale de construction est visible.
  await expect(page.locator('[data-test="prepare-week-open"]')).toBeVisible()

  // Les 3 bibliothèques (tâches, semaines types, import) restent accessibles depuis l'espace Modèles.
  await page.locator('[data-test="plan-view-templates"]').click()
  await page.waitForTimeout(600)
  await expect(page.locator('[data-test="preset-library-open"]')).toBeVisible()
  await expect(page.locator('[data-test="week-library-open"]')).toBeVisible()
  await expect(page.locator('[data-test="preset-import-open"]')).toBeVisible()
})

test('M6.C G — mobile : « Préparer ma semaine » accessible et utilisable à 390px', async ({ page }) => {
  test.setTimeout(90000)
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page)
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)
  const prepare = page.locator('[data-test="prepare-week-open"]')
  await prepare.scrollIntoViewIfNeeded()
  await expect(prepare).toBeVisible()
  await prepare.click()
  // Les points de départ sont présents et scrollables sur mobile.
  await expect(page.locator('[data-test="prepare-weektpl"]')).toBeVisible({ timeout: 8000 })
  await page.locator('[data-test="prepare-scratch"]').scrollIntoViewIfNeeded()
  await expect(page.locator('[data-test="prepare-scratch"]')).toBeVisible()
})
