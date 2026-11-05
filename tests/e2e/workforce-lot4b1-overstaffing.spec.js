// Smart Planning Lot 4B.1 (ADR-719) — contrat de SUREFFECTIF via la vraie UI.
// Prouve : détection d'un dépassement du besoin → dialog de confirmation → refus sans confirmation
// (aucune écriture) → confirmation autorisée → écriture. (Concurrence/signature périmée : PHPUnit.)
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

async function gotoGrid(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1000)
  await expect(page.locator('.planning-grid').first()).toBeVisible()
}

async function cells(page) {
  return page.$$eval('[data-droppable="1"]', els => els.map(e => ({
    emp: Number(e.dataset.emp), date: e.dataset.date,
    hasShift: !!e.querySelector('.plan-shift'), hasAbsence: !!e.querySelector('.plan-absence'),
  })))
}

test('sureffectif : dialog, refus sans confirmation, puis confirmation → écriture', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await gotoGrid(page)

  // Les salariés planifiables de la base E2E sont souvent déjà à ~48h/semaine (règle hebdo bloquante) ou
  // sans contrat. On SONDE donc empiriquement des salariés AYANT DE LA CAPACITÉ sur un jour libre commun,
  // avec une catégorie + template dédiés. Fenêtre courte distinctive pour éviter les collisions de besoins.
  const map = await cells(page)
  const byEmp = new Map()
  for (const c of map) { if (!byEmp.has(c.emp)) byEmp.set(c.emp, []); byEmp.get(c.emp).push(c) }

  const suffix = Date.now().toString().slice(-5)
  const cat = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/coverage-categories', body: { code: 'RT' + suffix, name: 'Route ' + suffix, color: '#3b82f6' } })
  const catId = cat.json.id || cat.json.data?.id
  const tpl = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/templates', body: { name: 'RouteT ' + suffix, start_time: '06:15', end_time: '06:45', coverage_category_id: catId, color: '#3b82f6' } })
  const tplId = tpl.json.id || tpl.json.data?.id

  // Cherche, par date, deux salariés distincts capables d'accueillir un shift 06:15-06:45 (201).
  let date = null; let filler = null; let target = null; let fillShiftId = null
  const dates = [...new Set(map.map(c => c.date))]
  for (const d of dates) {
    const candidates = [...byEmp.entries()]
      .filter(([, l]) => l.find(c => c.date === d && !c.hasShift && !c.hasAbsence))
      .map(([emp]) => emp)
    const capable = []
    for (const emp of candidates) {
      const r = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: emp, date: d, start_at: `${d} 06:15:00`, end_at: `${d} 06:45:00`, timezone: 'Europe/Paris', schedule_template_id: tplId } })
      if (r.status === 201) { capable.push({ emp, shiftId: r.json.id }) }
      if (capable.length === 2) break
    }
    if (capable.length === 2) {
      date = d
      filler = capable[0].emp; fillShiftId = capable[0].shiftId
      target = capable[1].emp
      // Libère la cellule du 2ᵉ (sa capacité est prouvée) pour la re-créer via l'UI (over-staffing).
      await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${capable[1].shiftId}` })
      break
    }
    // Nettoie les sondes de cette date si on n'a pas abouti.
    for (const c of capable) await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${c.shiftId}` })
  }
  test.skip(!date, 'Pas de deux salariés avec capacité le même jour (base E2E saturée).')

  // Besoin headcount 1 sur ce créneau → le « filler » le remplit (1/1) ; le « target » le dépasserait (2/1).
  const req = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/coverage', body: { date, start_time: '06:15', end_time: '06:45', headcount: 1, coverage_category_id: catId } })
  expect(req.status).toBeLessThan(300)
  expect(fillShiftId).toBeTruthy()

  // Vérifie que la fixture produit bien un sureffectif marginal (déterministe) avant de piloter l'UI.
  const prev = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts/overstaffing-preview', body: { employee_id: target, date, start_at: `${date} 06:15:00`, end_at: `${date} 06:45:00`, schedule_template_id: tplId } })
  expect(prev.status).toBe(200)
  expect(prev.json.overstaffed, 'la fixture doit créer un sureffectif').toBeTruthy()

  await gotoGrid(page)

  // Arme le modèle Route UNE fois (re-cliquer la carte la dé-armerait — toggle) puis clique la cellule.
  const card = page.locator('[data-test="palette-template"]', { hasText: 'RouteT ' + suffix }).first()
  await card.scrollIntoViewIfNeeded()
  await card.click()
  const clickCell = async () => {
    const tc = page.locator(`[data-droppable="1"][data-emp="${target}"][data-date="${date}"]`).first()
    await tc.scrollIntoViewIfNeeded()
    await tc.click()
  }
  await clickCell()
  const dialog = page.locator('[data-test="overstaffing-confirm"]')
  await expect(dialog).toBeVisible({ timeout: 10000 })

  // Refus sans confirmation : fermer (Échap) → aucune écriture pour le salarié cible.
  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
  await page.waitForTimeout(500)
  let count = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts/week?date=${date}` })
  let list = Array.isArray(count.json?.data) ? count.json.data : (count.json || [])
  expect(list.filter(s => s.employee_id === target && (s.date || '').startsWith(date)).length).toBe(0)

  // Re-déclenche (modèle toujours armé) puis CONFIRME → écriture effective.
  await clickCell()
  await expect(dialog).toBeVisible({ timeout: 10000 })
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts$/.test(r.url()) && r.request().method() === 'POST', { timeout: 15000 }),
    dialog.click(),
  ])
  expect(resp.status()).toBe(201)

  await page.waitForTimeout(600)
  count = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts/week?date=${date}` })
  list = Array.isArray(count.json?.data) ? count.json.data : (count.json || [])
  expect(list.filter(s => s.employee_id === target && (s.date || '').startsWith(date)).length).toBeGreaterThan(0)
})
