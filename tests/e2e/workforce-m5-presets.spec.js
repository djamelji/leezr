// Refonte Smart Planning — M5 (ADR-728) : onboarding presets JobDomain + favoris, depuis la VRAIE interface.
// A (importer les modèles métier) + C (ré-import = anti-doublon, aucun doublon) + D (favori personnel) + F (mobile).
// B (RBAC/provenance/idempotence côté service) + E (séparation tenant, homonyme) : couverts par PHPUnit
//   (WorkforcePresetImportM5Test) — l'import réutilise exactement le même service transactionnel.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })
const API = '/api/workforce/planning'

async function templatesTab(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'domcontentloaded' })
  const tab = page.locator('[data-test="plan-view-templates"]')
  await tab.waitFor({ state: 'visible', timeout: 30000 }); await tab.click(); await page.waitForTimeout(500)
}

async function openImport(page) {
  const [pv] = await Promise.all([
    page.waitForResponse(x => /\/presets\/import-preview/.test(x.url()) && x.request().method() === 'GET'),
    page.locator('[data-test="preset-import-open"]').click(),
  ])
  await expect(page.locator('[data-test="preset-import-apply"]')).toBeVisible({ timeout: 10000 })
  return pv.json()
}

async function runImport(page) {
  const [ir] = await Promise.all([
    page.waitForResponse(x => /\/presets\/import$/.test(x.url()) && x.request().method() === 'POST'),
    page.locator('[data-test="preset-import-apply"]').click(),
  ])
  return ir.json()
}

test('M5 scénario A — importer les modèles métier contextualisés par JobDomain', async ({ page }) => {
  test.setTimeout(160000)
  await loginCompany(page)
  await templatesTab(page)

  const prev = await openImport(page)
  expect(prev.has_presets).toBe(true)
  // Le JobDomain logistique propose au moins les 4 modèles de shift + 1 semaine type + tâches.
  expect((prev.schedule_templates || []).length).toBeGreaterThanOrEqual(4)
  await expect(page.locator('[data-test="preset-import-row"]').first()).toBeVisible()

  const res = await runImport(page)
  // 201 → résultat structuré (idempotent : created peut être vide si déjà configuré sur DEV).
  expect(Array.isArray(res.created)).toBe(true)
  await expect(page.locator('[data-test="preset-import-result"]')).toBeVisible()

  // Les modèles importés sont désormais présents dans la table (aucun Shift créé pour autant).
  const tpls = await apiRequest(page, { method: 'GET', path: `${API}/templates?all=1` })
  const names = (tpls.json?.data ?? tpls.json ?? []).map(t => t.name)
  expect(names).toContain('Shift journée')
})

test('M5 scénario C — ré-import : anti-doublon, aucun objet recréé', async ({ page }) => {
  test.setTimeout(160000)
  await loginCompany(page)
  await templatesTab(page)

  // 1er passage : garantit l'état "déjà importé".
  await openImport(page)
  await runImport(page)
  await expect(page.locator('[data-test="preset-import-result"]')).toBeVisible()
  await page.keyboard.press('Escape') // ferme le dialog
  await expect(page.locator('[data-test="preset-import-apply"]')).toBeHidden({ timeout: 10000 })

  // 2e passage : la prévisualisation ne propose plus de "nouveau", le ré-import ne crée rien.
  const prev2 = await openImport(page)
  expect(prev2.summary.new).toBe(0)
  await expect(page.locator('[data-test="preset-import-row"]').first()).toBeVisible()
  const res2 = await runImport(page)
  expect(res2.created.length).toBe(0) // invariant : jamais de doublon
})

test('M5 scénario D — favori personnel : étoiler un modèle, retrouver le favori', async ({ page }) => {
  test.setTimeout(160000)
  await loginCompany(page)
  await templatesTab(page)
  // S'assure qu'au moins un modèle existe (import idempotent).
  await openImport(page); await runImport(page)
  await page.locator('body').click({ position: { x: 5, y: 5 } }) // ferme le dialog
  await page.waitForTimeout(300)

  const star = page.locator('[data-test^="fav-schedule_template-"]').first()
  await star.scrollIntoViewIfNeeded()
  await expect(star).toBeVisible()

  // Étoiler → API favorited:true.
  const [r1] = await Promise.all([
    page.waitForResponse(x => /\/favorites\/toggle/.test(x.url()) && x.request().method() === 'POST'),
    star.click(),
  ])
  expect((await r1.json()).favorited).toBe(true)

  // Le favori est persisté côté serveur (retrouvable après rechargement).
  const favs = await apiRequest(page, { method: 'GET', path: `${API}/favorites?type=schedule_template` })
  expect((favs.json ?? []).length).toBeGreaterThanOrEqual(1)

  // Retirer (nettoyage) → favorited:false.
  const [r2] = await Promise.all([
    page.waitForResponse(x => /\/favorites\/toggle/.test(x.url()) && x.request().method() === 'POST'),
    star.click(),
  ])
  expect((await r2.json()).favorited).toBe(false)
})

test('M5 scénario F — mobile : ouvrir et parcourir l’import (scroll)', async ({ page }) => {
  test.setTimeout(140000)
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page)
  await templatesTab(page)

  const prev = await openImport(page)
  expect(prev.has_presets).toBe(true)
  await expect(page.locator('[data-test="preset-import-row"]').first()).toBeVisible()
  const apply = page.locator('[data-test="preset-import-apply"]')
  await apply.scrollIntoViewIfNeeded()
  await expect(apply).toBeVisible()
})
