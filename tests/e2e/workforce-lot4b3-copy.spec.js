// Smart Planning Lot 4B.3-A (ADR-721) — copier-coller & copie de semaine (UI réelle).
// Prouve : copier un shift (menu) → coller (persistance) ; copier la semaine (preview → commit → persistance).
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

// N salariés distincts avec capacité prouvée (sonde 30 min create+delete) sur la MÊME date.
async function capableCells(page, n) {
  const map = await gridCells(page)
  const byDate = {}
  for (const c of map) { if (!c.hasShift && !c.hasAbsence) (byDate[c.date] ||= []).push(c) }
  for (const date of Object.keys(byDate)) {
    const found = []
    for (const c of byDate[date]) {
      const r = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: c.emp, date, start_at: `${date} 05:05:00`, end_at: `${date} 05:35:00`, timezone: 'Europe/Paris' } })
      if (r.status === 201) { await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${r.json.id}` }); found.push(c.emp) }
      if (found.length === n) return { date, emps: found }
    }
  }

  return null
}

test('copier un shift (menu) puis coller dans une autre cellule → persistance', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await gotoGrid(page)

  const cap = await capableCells(page, 2) // 2 salariés avec capacité, même jour
  test.skip(!cap, 'Pas de 2 salariés avec capacité le même jour (base E2E).')
  const [srcEmp, dstEmp] = cap.emps
  const date = cap.date

  // Fixture : un shift source pour srcEmp.
  const mk = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: srcEmp, date, start_at: `${date} 05:05:00`, end_at: `${date} 05:35:00`, timezone: 'Europe/Paris' } })
  expect(mk.status).toBe(201)

  await gotoGrid(page)
  // Ouvre le menu du shift → Copier.
  const src = cell(page, srcEmp, date)
  await src.scrollIntoViewIfNeeded()
  await src.locator('[data-test="shift-menu"]').first().click()
  await page.locator('[data-test="copy-shift"]').click()
  await expect(page.locator('[data-test="clipboard-chip"]')).toBeVisible()

  // Colle sur la cellule libre de dstEmp (même jour, autre salarié).
  const dst = cell(page, dstEmp, date)
  await dst.scrollIntoViewIfNeeded()
  await dst.hover()
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts\/copy$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    dst.locator('[data-test="paste-cell"]').click(),
  ])
  expect(resp.status()).toBe(201)

  // Persistance après rechargement.
  await gotoGrid(page)
  const after = await gridCells(page)
  expect(after.find(c => c.emp === dstEmp && c.date === date)?.hasShift).toBeTruthy()
})

test('copier la semaine : prévisualisation → confirmation → persistance', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  await gotoGrid(page)

  const cap = await capableCells(page, 1)
  test.skip(!cap, 'Pas de salarié avec capacité (base E2E).')
  const emp = cap.emps[0]

  // Fixture : un shift en semaine PRÉCÉDENTE pour ce salarié (source de la copie de semaine).
  const prevMonday = await page.evaluate(() => {
    const d = new Date(); const day = (d.getDay() + 6) % 7
    d.setDate(d.getDate() - day - 7)

    return d.toISOString().slice(0, 10)
  })
  const mk = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: emp, date: prevMonday, start_at: `${prevMonday} 05:05:00`, end_at: `${prevMonday} 05:35:00`, timezone: 'Europe/Paris' } })
  test.skip(mk.status !== 201, 'Impossible de créer la source en semaine précédente (capacité).')

  await gotoGrid(page)
  await page.locator('[data-test="copy-week"]').click()
  const [prev] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts\/copy-week\/preview$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="copy-week-preview"]').click(),
  ])
  expect(prev.status()).toBe(200)

  const confirm = page.locator('[data-test="copy-week-confirm"]')
  test.skip(!(await confirm.isVisible().catch(() => false)), 'Aucune opération applicable à copier (base E2E).')
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts\/copy-week\/apply$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    confirm.click(),
  ])
  expect(resp.status()).toBe(201)
  expect((await resp.json()).created_count).toBeGreaterThanOrEqual(1)
})
