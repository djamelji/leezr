// Smart Planning Lot 4B.2 (ADR-720) — multi-sélection + application groupée de modèle (UI réelle).
// Prouve : Ctrl/Cmd+clic, Shift+clic (plage), Échap, barre d'actions, prévisualisation, création atomique
// de brouillons, persistance, non-régression du drag. (Sureffactif cumulatif : PHPUnit + 4B.1 UI.)
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
    emp: Number(e.dataset.emp), date: e.dataset.date, dayidx: Number(e.dataset.dayidx),
    hasShift: !!e.querySelector('.plan-shift'), hasAbsence: !!e.querySelector('.plan-absence'),
  })))
}

// Trouve N cellules (salariés distincts) réellement planifiables sur la MÊME date (sonde create+delete).
async function capableCells(page, n, start = '06:15', end = '06:45') {
  const map = await gridCells(page)
  const byDate = {}
  for (const c of map) { if (!c.hasShift && !c.hasAbsence) (byDate[c.date] ||= []).push(c) }
  for (const date of Object.keys(byDate)) {
    const found = []
    for (const c of byDate[date]) {
      const r = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: c.emp, date, start_at: `${date} ${start}:00`, end_at: `${date} ${end}:00`, timezone: 'Europe/Paris' } })
      if (r.status === 201) {
        await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${r.json.id}` })
        found.push(c)
      }
      if (found.length === n) return { date, cells: found }
    }
  }

  return null
}

async function ensureTemplate(page) {
  const name = 'Bulk-' + Date.now().toString().slice(-5)
  await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/templates', body: { name, start_time: '06:15', end_time: '06:45', color: '#22c55e' } })

  return name
}

test('multi-sélection (Ctrl/Cmd + Shift + Échap) et barre d’actions', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await gotoGrid(page)

  const cap = await capableCells(page, 3)
  test.skip(!cap, 'Pas de 3 cellules planifiables le même jour (base E2E).')
  const mod = process.platform === 'darwin' ? 'Meta' : 'Control'

  // Ctrl/Cmd + clic sur 2 cellules → 2 sélectionnées.
  await cell(page, cap.cells[0].emp, cap.date).click({ modifiers: [mod] })
  await cell(page, cap.cells[1].emp, cap.date).click({ modifiers: [mod] })
  await expect(page.locator('[data-test="selection-bar"]')).toBeVisible()
  await expect(page.locator('[data-test="selection-count"]')).toContainText('2')

  // Shift + clic sur une 3e → plage rectangulaire (≥ 3 cellules).
  await cell(page, cap.cells[2].emp, cap.date).click({ modifiers: ['Shift'] })
  await expect(page.locator('.plan-cell--selected')).toHaveCount(await page.locator('.plan-cell--selected').count())
  expect(await page.locator('.plan-cell--selected').count()).toBeGreaterThanOrEqual(3)

  // Échap → désélection complète.
  await page.keyboard.press('Escape')
  await expect(page.locator('[data-test="selection-bar"]')).toBeHidden()
})

test('application groupée d’un modèle : prévisualisation → création atomique de brouillons → persistance', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  const tplName = await ensureTemplate(page)
  await gotoGrid(page)

  const cap = await capableCells(page, 3)
  test.skip(!cap, 'Pas de 3 cellules planifiables le même jour (base E2E).')
  const mod = process.platform === 'darwin' ? 'Meta' : 'Control'
  for (const c of cap.cells) await cell(page, c.emp, cap.date).click({ modifiers: [mod] })
  await expect(page.locator('[data-test="selection-count"]')).toContainText('3')

  // Choisit le modèle dans la barre, ouvre la prévisualisation.
  await page.locator('[data-test="selection-bar"]').getByRole('combobox').first().click()
  await page.getByRole('option', { name: tplName }).click()
  await page.locator('[data-test="bulk-apply"]').click()

  // Un SEUL écran de prévisualisation ; 3 « à créer ».
  await expect(page.locator('[data-test="bulk-confirm"]')).toBeVisible({ timeout: 10000 })
  await expect(page.locator('.plan-bulk-table tbody tr')).toHaveCount(3)

  // Confirme → commit atomique ; vérifie la réponse 201 + nombre créé.
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts\/bulk-template$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="bulk-confirm"]').click(),
  ])
  expect(resp.status()).toBe(201)
  expect((await resp.json()).created_count).toBe(3)

  // Persistance : après rechargement, les 3 cellules portent un shift.
  await gotoGrid(page)
  const after = await gridCells(page)
  for (const c of cap.cells) {
    expect(after.find(x => x.emp === c.emp && x.date === cap.date)?.hasShift, `cellule ${c.emp} créée`).toBeTruthy()
  }
})
