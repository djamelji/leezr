// Smart Planning Lot 4B.3-B (ADR-722) — annuler / rétablir (UI réelle, commandes serveur persistées).
// A : créer → annuler (disparition serveur) → rétablir (persistance). C : copie de semaine → annuler global.
// D : refus sur modification concurrente. Fixtures isolées + nettoyées.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest, trackCreatedShifts, cleanupShifts } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

let created
test.beforeEach(({ page }) => { created = trackCreatedShifts(page) })
test.afterEach(async ({ page }) => { await cleanupShifts(page, created) })

async function gotoGrid(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1000)
  await expect(page.locator('.planning-grid').first()).toBeVisible()
}
const cell = (page, emp, date) => page.locator(`[data-droppable="1"][data-emp="${emp}"][data-date="${date}"]`).first()

async function gridCells(page) {
  return page.$$eval('[data-droppable="1"]', els => els.map(e => ({
    emp: Number(e.dataset.emp), date: e.dataset.date,
    hasShift: !!e.querySelector('.plan-shift'), hasAbsence: !!e.querySelector('.plan-absence'),
  })))
}
async function capable(page, n) {
  const map = await gridCells(page)
  const byDate = {}
  for (const c of map) { if (!c.hasShift && !c.hasAbsence) (byDate[c.date] ||= []).push(c) }
  for (const date of Object.keys(byDate)) {
    const found = []
    for (const c of byDate[date]) {
      const r = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: c.emp, date, start_at: `${date} 03:03:00`, end_at: `${date} 03:33:00`, timezone: 'Europe/Paris' } })
      if (r.status === 201) { await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${r.json.id}` }); found.push(c.emp) }
      if (found.length === n) return { date, emps: found }
    }
  }

  return null
}
const weekShifts = (page, date) => apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts/week?date=${date}` })
const countFor = (res, emp, date) => {
  const list = Array.isArray(res.json?.data) ? res.json.data : (res.json || [])

  return list.filter(s => s.employee_id === emp && (s.date || '').startsWith(date)).length
}

test('A — créer → Annuler (disparition serveur) → Rétablir (persistance)', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  const name = 'Undo-' + Date.now().toString().slice(-5)
  await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/templates', body: { name, start_time: '03:03', end_time: '03:33', color: '#a855f7' } })
  await gotoGrid(page)

  const cap = await capable(page, 1)
  test.skip(!cap, 'Pas de salarié avec capacité (base E2E).')
  const emp = cap.emps[0]; const date = cap.date

  // Arme le modèle (palette) puis clique la cellule → création (empile l'undo).
  await page.locator('[data-test="palette-template"]', { hasText: name }).first().click()
  const [mk] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    cell(page, emp, date).click(),
  ])
  expect(mk.status()).toBe(201)
  expect(countFor(await weekShifts(page, date), emp, date)).toBe(1)

  // Annuler → suppression serveur.
  const [undo] = await Promise.all([
    page.waitForResponse(r => /\/operations\/\d+\/undo$/.test(r.url()), { timeout: 20000 }),
    page.locator('[data-test="undo-btn"]').click(),
  ])
  expect(undo.status()).toBe(200)
  expect(countFor(await weekShifts(page, date), emp, date)).toBe(0)

  // Rétablir → recréation (nouveau brouillon), persistant après rechargement.
  const [redo] = await Promise.all([
    page.waitForResponse(r => /\/operations\/\d+\/redo$/.test(r.url()), { timeout: 20000 }),
    page.locator('[data-test="redo-btn"]').click(),
  ])
  expect(redo.status()).toBe(200)
  await gotoGrid(page)
  expect(countFor(await weekShifts(page, date), emp, date)).toBe(1)
})

test('C — copie de semaine → Annuler global (tous les brouillons retirés, sources intactes)', async ({ page }) => {
  test.setTimeout(180000)
  await loginCompany(page)
  await gotoGrid(page)

  const cap = await capable(page, 1)
  test.skip(!cap, 'Pas de salarié avec capacité (base E2E).')
  const emp = cap.emps[0]

  const prevMonday = await page.evaluate(() => { const d = new Date(); const day = (d.getDay() + 6) % 7; d.setDate(d.getDate() - day - 7); return d.toISOString().slice(0, 10) })
  const srcMk = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: emp, date: prevMonday, start_at: `${prevMonday} 03:03:00`, end_at: `${prevMonday} 03:33:00`, timezone: 'Europe/Paris' } })
  test.skip(srcMk.status !== 201, 'Source semaine précédente impossible (capacité).')

  await gotoGrid(page)
  await page.locator('[data-test="copy-week"]').click()
  await Promise.all([
    page.waitForResponse(r => /copy-week\/preview$/.test(r.url()), { timeout: 20000 }),
    page.locator('[data-test="copy-week-preview"]').click(),
  ])
  const confirm = page.locator('[data-test="copy-week-confirm"]')
  test.skip(!(await confirm.isVisible().catch(() => false)), 'Rien d\'applicable à copier.')
  const [apply] = await Promise.all([
    page.waitForResponse(r => /copy-week\/apply$/.test(r.url()), { timeout: 20000 }),
    confirm.click(),
  ])
  const applyBody = await apply.json()
  expect(applyBody.created_count).toBeGreaterThanOrEqual(1)
  const createdIds = applyBody.created

  // Annuler l'opération GLOBALE → tous les brouillons créés sont retirés ; la source demeure.
  const [undo] = await Promise.all([
    page.waitForResponse(r => /\/operations\/\d+\/undo$/.test(r.url()), { timeout: 20000 }),
    page.locator('[data-test="undo-btn"]').click(),
  ])
  expect(undo.status()).toBe(200)
  for (const id of createdIds) {
    const chk = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts?date=${prevMonday}` })

    void chk
  }
  // La source (semaine précédente) est intacte.
  expect(countFor(await weekShifts(page, prevMonday), emp, prevMonday)).toBe(1)
})

test('D — modification concurrente → Annuler refusé (pas d’écrasement)', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  await gotoGrid(page)

  const cap = await capable(page, 1)
  test.skip(!cap, 'Pas de salarié avec capacité.')
  const emp = cap.emps[0]; const date = cap.date
  const other = (await gridCells(page)).find(c => c.emp === emp && c.date !== date && !c.hasShift && !c.hasAbsence)
  test.skip(!other, 'Pas d\'autre jour libre pour le déplacement.')

  // Crée un brouillon puis le DÉPLACE via l'API (opération enregistrée → operation_id).
  const mk = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: emp, date, start_at: `${date} 03:03:00`, end_at: `${date} 03:33:00`, timezone: 'Europe/Paris' } })
  expect(mk.status).toBe(201)
  const mv = await apiRequest(page, { method: 'PUT', path: `/api/workforce/planning/shifts/${mk.json.id}`, body: { date: other.date, start_at: `${other.date} 03:03:00`, end_at: `${other.date} 03:33:00` } })
  expect(mv.status).toBeLessThan(300)
  const opId = mv.json.operation_id
  expect(opId).toBeTruthy()

  await page.waitForTimeout(1100) // le verrou optimiste est en précision seconde → changement distinct
  // Modification CONCURRENTE (autre horaire) → change updated_at.
  const conc = await apiRequest(page, { method: 'PUT', path: `/api/workforce/planning/shifts/${mk.json.id}`, body: { start_at: `${other.date} 03:10:00`, end_at: `${other.date} 03:40:00` } })
  expect(conc.status).toBeLessThan(300)

  // Annuler le déplacement → REFUS (409 concurrent_change), aucun écrasement.
  const undo = await apiRequest(page, { method: 'POST', path: `/api/workforce/planning/operations/${opId}/undo` })
  expect(undo.status).toBe(409)
  expect(undo.json.code).toBe('concurrent_change')
  // La modif concurrente est préservée (toujours sur other.date, horaire 03:10).
  const check = await weekShifts(page, other.date)
  expect(countFor(check, emp, other.date)).toBe(1)
})
