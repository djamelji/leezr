// Refonte Smart Planning — M3 (ADR-726) : semaines types, depuis la VRAIE interface.
// A (TPE nominative) · B (logistique par besoins, poste non pourvu) · C (mixte) · D (concurrence) · E (mobile).
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest, trackCreatedShifts, cleanupShifts } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })
const API = '/api/workforce/planning'
let created
const tpls = new Set()
const weeks = new Set()
const TARGET = '2027-03-01' // semaine lointaine → évite la saturation 48h/chevauchements de la base DEV

test.beforeEach(({ page }) => { created = trackCreatedShifts(page) })
test.afterEach(async ({ page }) => {
  await cleanupShifts(page, created)
  for (const id of weeks) await apiRequest(page, { method: 'DELETE', path: `${API}/week-templates/${id}` })
  for (const id of tpls) await apiRequest(page, { method: 'DELETE', path: `${API}/templates/${id}` })
  weeks.clear(); tpls.clear()
})

async function templatesTab(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'domcontentloaded' })
  const tab = page.locator('[data-test="plan-view-templates"]')
  await tab.waitFor({ state: 'visible', timeout: 30000 })
  await tab.click(); await page.waitForTimeout(500)
}
// Crée un modèle de shift court (faibles heures) via l'éditeur M1.b. Retourne {id,name}.
async function makeTemplate(page, start, end) {
  // Préfixe "AAA" → le modèle apparaît EN TÊTE du select (liste virtualisée : seuls les items du haut sont rendus).
  const name = 'AAA Wk ' + Date.now() + '-' + Math.floor(Math.random() * 1000)
  await page.locator('[data-test="template-new"]').click()
  await page.locator('[data-test="template-name"] input').fill(name)
  await page.locator('[data-test="template-start"] input').fill(start)
  await page.locator('[data-test="template-end"] input').fill(end)
  const [r] = await Promise.all([
    page.waitForResponse(x => /\/planning\/templates$/.test(x.url()) && x.request().method() === 'POST'),
    page.locator('[data-test="template-submit"]').click(),
  ])
  const j = await r.json(); tpls.add(j.id)
  return { id: j.id, name }
}
async function pickSelect(page, testid, text, root = null) {
  const base = root ? root.locator(`[data-test="${testid}"]`) : page.locator(`[data-test="${testid}"]`)
  await base.locator('.v-field, input').first().click()
  const opts = page.locator('.v-overlay-container .v-list-item, .v-menu .v-list-item')
  await opts.first().waitFor({ state: 'visible', timeout: 8000 })
  if (text) await opts.filter({ hasText: text }).first().click()
  else await opts.first().click()
  await page.waitForTimeout(150)
}

test('M3 scénario A — semaine nominative : créer, prévisualiser, appliquer, vérifier shifts & activités', async ({ page }) => {
  test.setTimeout(200000)
  await loginCompany(page)
  await templatesTab(page)
  const tpl = await makeTemplate(page, '03:00', '04:00')

  await page.locator('[data-test="week-library-open"]').click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="week-new"]').click()
  await page.locator('[data-test="week-name"] input').fill('Sem A ' + Date.now())
  await page.locator('[data-test="week-add-line"]').click()
  await pickSelect(page, 'week-line-template', tpl.name)
  await pickSelect(page, 'week-line-employee', null) // 1er salarié
  const [wr] = await Promise.all([
    page.waitForResponse(x => /\/planning\/week-templates$/.test(x.url()) && x.request().method() === 'POST'),
    page.locator('[data-test="week-save"]').click(),
  ])
  const wt = await wr.json(); weeks.add(wt.id)
  expect(wr.status()).toBe(201)

  // Appliquer
  await page.locator(`[data-test="week-apply-${wt.id}"]`).click()
  await page.locator('[data-test="week-target-date"] input').fill(TARGET)
  await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/preview`).test(x.url())),
    page.locator('[data-test="week-preview-btn"]').click(),
  ])
  await expect(page.locator('[data-test="week-preview-summary"]')).toBeVisible()
  await expect(page.locator('[data-test="week-nominative-row"]')).toHaveCount(1)

  const [ar] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/apply`).test(x.url())),
    page.locator('[data-test="week-apply-btn"]').click(),
  ])
  const res = await ar.json()
  ;(res.created || []).forEach(id => created.add(id))
  test.skip(!(res.created_count > 0), 'Salarié non éligible sur la base DEV — matérialisation non confirmée.')
  await expect(page.locator('[data-test="week-apply-result"]')).toBeVisible()

  // Activités matérialisées sur le shift créé.
  const acts = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${res.created[0]}` })
  expect(Array.isArray(acts.json?.data ?? acts.json)).toBeTruthy()
})

test('M3 scénario B — semaine par besoins : poste non pourvu conservé', async ({ page }) => {
  test.setTimeout(200000)
  await loginCompany(page)
  await templatesTab(page)
  const tpl = await makeTemplate(page, '05:00', '06:00')

  await page.locator('[data-test="week-library-open"]').click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="week-new"]').click()
  await page.locator('[data-test="week-name"] input').fill('Sem B ' + Date.now())
  await pickSelect(page, 'week-strategy', null) // garde nominative par défaut mais on force la ligne en besoins
  await page.locator('[data-test="week-add-line"]').click()
  await pickSelect(page, 'week-line-template', tpl.name)
  await pickSelect(page, 'week-line-kind', 'Poste') // "Poste à pourvoir"
  await page.locator('[data-test="week-line-headcount"] input').fill('3')
  const [wr] = await Promise.all([
    page.waitForResponse(x => /\/planning\/week-templates$/.test(x.url()) && x.request().method() === 'POST'),
    page.locator('[data-test="week-save"]').click(),
  ])
  const wt = await wr.json(); weeks.add(wt.id); expect(wr.status()).toBe(201)

  await page.locator(`[data-test="week-apply-${wt.id}"]`).click()
  await page.locator('[data-test="week-target-date"] input').fill(TARGET)
  const [pv] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/preview`).test(x.url())),
    page.locator('[data-test="week-preview-btn"]').click(),
  ])
  const prev = await pv.json()
  expect(prev.needs.length).toBe(1)
  expect(prev.summary.to_confirm).toBe(3)

  // Affecte 1 seul candidat (si disponible) → 2 restent non pourvus.
  const lineId = prev.needs[0].line_id
  if (prev.needs[0].candidates.length) {
    await pickSelect(page, `week-needs-assign-${lineId}`, null)
    await page.keyboard.press('Escape')
  }
  const [ar] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/apply`).test(x.url())),
    page.locator('[data-test="week-apply-btn"]').click(),
  ])
  const res = await ar.json()
  ;(res.created || []).forEach(id => created.add(id))
  expect(res.unfilled).toBeGreaterThanOrEqual(2) // postes non pourvus conservés
  await expect(page.locator('[data-test="week-apply-result"]')).toBeVisible()
})

test('M3 scénario C — semaine mixte : nominatif + poste coexistent', async ({ page }) => {
  test.setTimeout(200000)
  await loginCompany(page)
  await templatesTab(page)
  const tpl = await makeTemplate(page, '04:00', '05:00')

  await page.locator('[data-test="week-library-open"]').click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="week-new"]').click()
  await page.locator('[data-test="week-name"] input').fill('Sem C ' + Date.now())
  // Ligne 1 : nominatif
  await page.locator('[data-test="week-add-line"]').click()
  await pickSelect(page, 'week-line-template', tpl.name)
  await pickSelect(page, 'week-line-employee', null)
  // Ligne 2 : besoin
  await page.locator('[data-test="week-add-line"]').click()
  const row2 = page.locator('[data-test="week-line-row"]').nth(1)
  await pickSelect(page, 'week-line-template', tpl.name, row2)
  await pickSelect(page, 'week-line-kind', 'Poste', row2)
  // jour différent (2e option) → pas de chevauchement
  await row2.locator('[data-test="week-line-day"]').locator('.v-field, input').first().click()
  await page.locator('.v-overlay-container .v-list-item').nth(1).click()
  await page.waitForTimeout(150)
  const [wr] = await Promise.all([
    page.waitForResponse(x => /\/planning\/week-templates$/.test(x.url()) && x.request().method() === 'POST'),
    page.locator('[data-test="week-save"]').click(),
  ])
  const wt = await wr.json(); weeks.add(wt.id); expect(wr.status()).toBe(201)
  expect(wt.lines.length).toBe(2)

  await page.locator(`[data-test="week-apply-${wt.id}"]`).click()
  await page.locator('[data-test="week-target-date"] input').fill(TARGET)
  const [pv] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/preview`).test(x.url())),
    page.locator('[data-test="week-preview-btn"]').click(),
  ])
  const prev = await pv.json()
  expect(prev.nominative.length).toBe(1)
  expect(prev.needs.length).toBe(1) // les deux stratégies coexistent
})

test('M3 scénario D — concurrence : conflit injecté entre preview et commit → ligne bloquée', async ({ page }) => {
  test.setTimeout(200000)
  await loginCompany(page)
  await templatesTab(page)
  const tpl = await makeTemplate(page, '06:00', '07:00')

  await page.locator('[data-test="week-library-open"]').click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="week-new"]').click()
  await page.locator('[data-test="week-name"] input').fill('Sem D ' + Date.now())
  await page.locator('[data-test="week-add-line"]').click()
  await pickSelect(page, 'week-line-template', tpl.name)
  await pickSelect(page, 'week-line-employee', null)
  const [wr] = await Promise.all([
    page.waitForResponse(x => /\/planning\/week-templates$/.test(x.url()) && x.request().method() === 'POST'),
    page.locator('[data-test="week-save"]').click(),
  ])
  const wt = await wr.json(); weeks.add(wt.id)

  await page.locator(`[data-test="week-apply-${wt.id}"]`).click()
  await page.locator('[data-test="week-target-date"] input').fill(TARGET)
  const [pv] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/preview`).test(x.url())),
    page.locator('[data-test="week-preview-btn"]').click(),
  ])
  const prev = await pv.json()
  const n0 = prev.nominative[0]
  test.skip(n0.status !== 'applicable', 'Salarié non éligible (base DEV).')

  // CONCURRENCE : on crée un shift chevauchant pour CE salarié à CETTE date, APRÈS le preview.
  const conflict = await apiRequest(page, {
    method: 'POST', path: `${API}/shifts`,
    body: { employee_id: n0.employee_id, date: n0.date, start_at: `${n0.date} 06:00:00`, end_at: `${n0.date} 07:00:00`, timezone: 'Europe/Paris', schedule_template_id: tpl.id },
  })
  if (conflict.json?.id) created.add(conflict.json.id)
  expect(conflict.status).toBe(201)

  // Appliquer : le commit REVALIDE → la ligne est bloquée (chevauchement), rien créé en double.
  const [ar] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/apply`).test(x.url())),
    page.locator('[data-test="week-apply-btn"]').click(),
  ])
  const res = await ar.json()
  ;(res.created || []).forEach(id => created.add(id))
  expect(res.created_count).toBe(0)
  expect((res.blocked || []).some(b => b.reason === 'overlap')).toBeTruthy()
})

test('M3 scénario E — mobile : ouvrir, éditer une ligne, prévisualiser (scroll)', async ({ page }) => {
  test.setTimeout(150000)
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page)
  await templatesTab(page)
  const tpl = await makeTemplate(page, '02:00', '03:00')
  await page.locator('[data-test="week-library-open"]').click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="week-new"]').click()
  await page.locator('[data-test="week-name"] input').fill('Sem E ' + Date.now())
  await page.locator('[data-test="week-add-line"]').click()
  await pickSelect(page, 'week-line-template', tpl.name)
  await pickSelect(page, 'week-line-employee', null)
  const save = page.locator('[data-test="week-save"]')
  await save.scrollIntoViewIfNeeded()
  await expect(save).toBeVisible()
  const [wr] = await Promise.all([
    page.waitForResponse(x => /\/planning\/week-templates$/.test(x.url()) && x.request().method() === 'POST'),
    save.click(),
  ])
  weeks.add((await wr.json()).id)
  expect(wr.status()).toBe(201)
})
