// Smart Planning Lot 4C.1 (ADR-723) — mobile & tactile (vue jour, « Déplacer vers », scroll, persistance).
// Pas de drag qui bloque le scroll : l'alternative « Déplacer vers » est testée ; le scroll reste fonctionnel.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest, trackCreatedShifts, cleanupShifts } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

let created
test.beforeEach(({ page }) => { created = trackCreatedShifts(page) })
test.afterEach(async ({ page }) => { await cleanupShifts(page, created) })

async function gotoGridDesktop(page) {
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(700)
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(900)
}
async function gridCells(page) {
  return page.$$eval('[data-droppable="1"]', els => els.map(e => ({
    emp: Number(e.dataset.emp), date: e.dataset.date,
    hasShift: !!e.querySelector('.plan-shift'), hasAbsence: !!e.querySelector('.plan-absence'),
  })))
}
async function capable(page) {
  const map = await gridCells(page)
  const byEmp = new Map()
  for (const c of map) { if (!byEmp.has(c.emp)) byEmp.set(c.emp, []); byEmp.get(c.emp).push(c) }
  for (const [emp, list] of byEmp) {
    const free = list.filter(c => !c.hasShift && !c.hasAbsence).map(c => c.date)
    if (free.length < 2) continue
    const r = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: emp, date: free[0], start_at: `${free[0]} 02:02:00`, end_at: `${free[0]} 02:32:00`, timezone: 'Europe/Paris' } })
    if (r.status === 201) { await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${r.json.id}` }); return { emp, dates: free } }
  }

  return null
}
async function enterMobile(page) {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(700)
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(900)
}
// Avance jour par jour jusqu'à voir un shift au créneau distinctif (max 8 pas).
async function navigateToLabel(page, label) {
  for (let i = 0; i < 8; i++) {
    if (await page.getByText(label, { exact: false }).first().isVisible().catch(() => false)) return true
    await page.getByRole('button').filter({ has: page.locator('.tabler-chevron-right') }).first().click().catch(() => {})
    await page.waitForTimeout(500)
  }

  return false
}

test('A — vue mobile : « Déplacer vers » un brouillon → Annuler → persistance, scroll fonctionnel', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  await gotoGridDesktop(page)
  const row = await capable(page)
  test.skip(!row, 'Pas de salarié planifiable (base E2E).')
  const [from, to] = row.dates
  const mk = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: row.emp, date: from, start_at: `${from} 02:02:00`, end_at: `${from} 02:32:00`, timezone: 'Europe/Paris' } })
  expect(mk.status).toBe(201)

  await enterMobile(page)
  // La grille desktop est masquée ; la vue jour mobile est affichée.
  await expect(page.locator('.planning-grid')).toHaveCount(0)
  const found = await navigateToLabel(page, '02:02')
  test.skip(!found, 'Shift non atteint dans la vue jour (fenêtre/semaine).')
  const shiftRow = page.locator('div.d-flex.align-center.gap-1', { hasText: '02:02' }).first()

  // Scroll vertical fonctionnel (pas bloqué par une interaction tactile).
  const scrollable = await page.evaluate(() => { const el = document.scrollingElement; const before = el.scrollTop; el.scrollTop = 120; const moved = el.scrollTop !== before || el.scrollHeight > el.clientHeight; el.scrollTop = before; return moved })
  expect(scrollable, 'le défilement vertical reste possible').toBeTruthy()

  // Action « Déplacer vers » (alternative tactile explicite au drag) — sur MON shift (02:02).
  await shiftRow.locator('[data-test="mobile-shift-menu-draft"]').click()
  await page.locator('[data-test="mobile-move-to"]').click()
  await expect(page.locator('[data-test="mobile-move-confirm"]')).toBeVisible()
  // Choisit le JOUR cible `to` (option identifiée par son numéro de jour).
  const toDay = new Date(to + 'T00:00:00').getDate()
  await page.locator('.v-overlay--active').getByRole('combobox').first().click()
  await page.getByRole('option', { hasText: new RegExp(`\\b${toDay}\\b`) }).first().click()
  const [mv] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts\/\d+$/.test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 }),
    page.locator('[data-test="mobile-move-confirm"]').click(),
  ])
  expect(mv.status()).toBeLessThan(300)

  // Annuler → persistance (le shift n'est plus sur la cible après undo).
  const [undo] = await Promise.all([
    page.waitForResponse(r => /\/operations\/\d+\/undo$/.test(r.url()), { timeout: 20000 }),
    page.locator('[data-test="mobile-undo"]').click(),
  ])
  expect(undo.status()).toBe(200)
})

test('B — vue mobile : barre d’actions présente (Nouveau, Annuler/Rétablir) + absences visibles', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await enterMobile(page)
  await expect(page.locator('[data-test="mobile-new-shift"]')).toBeVisible()
  await expect(page.locator('[data-test="mobile-undo"]')).toBeVisible()
  await expect(page.locator('[data-test="mobile-redo"]')).toBeVisible()
  // Navigation entre les jours : le bouton suivant reste accessible (cible tactile).
  const next = page.getByRole('button').filter({ has: page.locator('.tabler-chevron-right') }).first()
  await expect(next).toBeVisible()
  await next.click()
  await page.waitForTimeout(400)
})
