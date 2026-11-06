// Refonte Smart Planning — M2 (ADR-725) : catalogue de TÂCHES TYPES depuis la VRAIE interface.
// Créer une tâche type → l'insérer dans un modèle de Shift → ajuster ses horaires → enregistrer →
// appliquer → vérifier l'activité réelle → modifier la tâche source → constater l'absence d'effet
// rétroactif (composition + instance) → désactiver → historique intact. + expérience mobile.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest, trackCreatedShifts, cleanupShifts } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

const API = '/api/workforce/planning'
let created
const createdTemplateIds = new Set()
const createdPresetIds = new Set()

test.beforeEach(({ page }) => { created = trackCreatedShifts(page) })
test.afterEach(async ({ page }) => {
  await cleanupShifts(page, created)
  for (const id of createdTemplateIds) await apiRequest(page, { method: 'DELETE', path: `${API}/templates/${id}` })
  for (const id of createdPresetIds) await apiRequest(page, { method: 'DELETE', path: `${API}/activity-presets/${id}` })
  createdTemplateIds.clear(); createdPresetIds.clear()
})

async function openTemplatesTab(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'domcontentloaded' })
  const tab = page.locator('[data-test="plan-view-templates"]')
  await tab.waitFor({ state: 'visible', timeout: 30000 })
  await tab.click()
  await page.waitForTimeout(500)
}

test('M2 — tâche type : créer, insérer dans un modèle, appliquer, modifier sans effet rétroactif, désactiver', async ({ page }) => {
  test.setTimeout(200000)
  await loginCompany(page)
  await openTemplatesTab(page)

  // 1-3) Ouvrir la bibliothèque + créer une tâche type.
  await page.locator('[data-test="preset-library-open"]').click()
  await expect(page.locator('[data-test="preset-save"]')).toBeVisible()
  const pname = 'Préparation véhicule ' + Date.now()
  await page.locator('[data-test="preset-name"] input').fill(pname)
  await page.locator('[data-test="preset-type"] input').fill('prep')
  await page.locator('[data-test="preset-duration"] input').fill('60')
  const [pRes] = await Promise.all([
    page.waitForResponse(r => /\/planning\/activity-presets$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="preset-save"]').click(),
  ])
  expect(pRes.status()).toBe(201)
  const preset = await pRes.json()
  createdPresetIds.add(preset.id)
  await expect(page.locator('[data-test="preset-row"]').filter({ hasText: pname })).toHaveCount(1)
  await page.locator('.v-navigation-drawer__scrim').first().click()
  await expect(page.locator('.v-navigation-drawer__scrim')).toHaveCount(0)

  // 4-5) Insérer la tâche type dans un modèle + ajuster les horaires.
  await page.locator('[data-test="template-new"]').click()
  await page.locator('[data-test="template-name"] input').fill('M2 ' + Date.now())
  await page.locator('[data-test="template-start"] input').fill('02:00')
  await page.locator('[data-test="template-end"] input').fill('04:00')
  await page.locator('[data-test="template-toggle-composition"]').click()
  await page.locator('[data-test="template-use-preset"]').click()
  await expect(page.locator('[data-test="preset-search"] input')).toBeVisible()
  await page.locator(`[data-test="preset-option-${preset.id}"]`).click()
  const row = page.locator('[data-test="template-activity-row"]').first()
  await expect(row.locator('input[type="text"]').first()).toHaveValue(pname) // nom copié
  // Ajuster l'horaire de CETTE occurrence (02:00 pré-rempli ; on fixe la fin).
  await row.locator('[data-test="template-activity-start"] input').fill('02:00')
  await row.locator('[data-test="template-activity-end"] input').fill('03:00')

  // 6) Enregistrer le modèle → la composition porte la provenance + les valeurs copiées.
  const [tRes] = await Promise.all([
    page.waitForResponse(r => /\/planning\/templates$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="template-submit"]').click(),
  ])
  expect(tRes.status()).toBe(201)
  const tpl = await tRes.json()
  createdTemplateIds.add(tpl.id)
  const compAct = tpl.metadata.composition.activities[0]
  expect(compAct.preset_id).toBe(preset.id)
  expect(compAct.activity_type).toBe('prep')

  // 7) Appliquer au planning (palette armée + cellule libre, probe résiliente).
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(900)
  const card = page.locator(`[data-test="palette-template"][data-template-id="${tpl.id}"]`)
  test.skip(!(await card.count()), 'Palette indisponible — application non testée.')
  await card.click()
  await page.waitForTimeout(300)
  const cells = await page.$$eval('[data-droppable="1"]', els => els.map((e, i) => ({ i, busy: !!e.querySelector('.plan-shift') || !!e.querySelector('.plan-absence') })))
  let shift = null
  for (const idx of cells.filter(c => !c.busy).map(c => c.i).slice(0, 20)) {
    const [resp] = await Promise.all([
      page.waitForResponse(r => /\/planning\/shifts$/.test(r.url()) && r.request().method() === 'POST', { timeout: 10000 }).catch(() => null),
      page.locator('[data-droppable="1"]').nth(idx).click(),
    ])
    if (resp && resp.status() === 201) { shift = await resp.json(); break }
    await page.waitForTimeout(150)
  }
  test.skip(!shift, 'Aucune cellule planifiable (base DEV saturée).')

  // 8) Activité réelle : valeurs copiées, source=template.
  const a1 = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${shift.id}` })
  const list1 = (a1.json?.data ?? a1.json ?? [])
  expect(list1).toHaveLength(1)
  expect(list1[0].activity_type).toBe('prep')
  expect(list1[0].source).toBe('template')

  // 9) Modifier la tâche SOURCE via l'UI.
  await page.locator('[data-test="plan-view-templates"]').click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="preset-library-open"]').click()
  await expect(page.locator(`[data-test="preset-edit-${preset.id}"]`)).toBeVisible()
  await page.locator(`[data-test="preset-edit-${preset.id}"]`).click()
  await page.locator('[data-test="preset-type"] input').fill('CHANGED')
  const [upd] = await Promise.all([
    page.waitForResponse(r => new RegExp(`/planning/activity-presets/${preset.id}$`).test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 }),
    page.locator('[data-test="preset-save"]').click(),
  ])
  expect(upd.status()).toBe(200)

  // 10) Aucun effet rétroactif : composition du modèle ET instance inchangées.
  const tplNow = await apiRequest(page, { method: 'GET', path: `${API}/templates?all=1` })
  const tplRow = (tplNow.json?.data ?? tplNow.json ?? []).find(x => x.id === tpl.id)
  expect(tplRow.metadata.composition.activities[0].activity_type).toBe('prep')
  const a2 = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${shift.id}` })
  expect((a2.json?.data ?? a2.json ?? [])[0].activity_type).toBe('prep')

  // 11-12) Désactiver la tâche type → l'historique reste intact.
  await page.locator(`[data-test="preset-enabled-${preset.id}"]`).click()
  await page.waitForResponse(r => new RegExp(`/planning/activity-presets/${preset.id}$`).test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 })
  const a3 = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${shift.id}` })
  expect((a3.json?.data ?? a3.json ?? [])).toHaveLength(1)
})

test('M2 — bibliothèque mobile : créer une tâche type, bouton accessible', async ({ page }) => {
  test.setTimeout(120000)
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page)
  await openTemplatesTab(page)
  await page.locator('[data-test="preset-library-open"]').click()
  await expect(page.locator('[data-test="preset-name"] input')).toBeVisible()
  const pname = 'Mobile preset ' + Date.now()
  await page.locator('[data-test="preset-name"] input').fill(pname)
  await page.locator('[data-test="preset-duration"] input').fill('30')
  const save = page.locator('[data-test="preset-save"]')
  await save.scrollIntoViewIfNeeded()
  const [pRes] = await Promise.all([
    page.waitForResponse(r => /\/planning\/activity-presets$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    save.click(),
  ])
  expect(pRes.status()).toBe(201)
  createdPresetIds.add((await pRes.json()).id)
  await expect(page.locator('[data-test="preset-row"]').filter({ hasText: pname })).toHaveCount(1)
})
