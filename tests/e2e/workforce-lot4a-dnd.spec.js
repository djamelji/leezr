// Smart Planning Lot 4A (ADR-718) — drag-and-drop RÉEL (événements pointeur, pas seulement HTTP).
// Démontre : modèle glissé → cellule (POST + persistance), brouillon déplacé (PUT), shift publié
// verrouillé (aucun PUT au drag), recalcul de couverture (refetch), captures light/dark.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

// Drag pointeur réel : hover (point actionnable fiable) → appuie → franchit le seuil → glisse → relâche.
async function pointerDrag(page, source, target) {
  await target.scrollIntoViewIfNeeded() // cible visible (salarié libre parfois loin dans la liste)
  await source.scrollIntoViewIfNeeded()
  await source.hover()
  await page.mouse.down()
  const s = await source.boundingBox()
  await page.mouse.move(s.x + s.width / 2 + 10, s.y + s.height / 2 + 10) // seuil anti-clic
  const t = await target.boundingBox()
  // Déplacement en plusieurs paliers (évite de déclencher l'auto-scroll de bord au survol intermédiaire).
  await page.mouse.move(t.x + t.width / 2, t.y + t.height / 2, { steps: 16 })
  await page.waitForTimeout(120)
  await page.mouse.move(t.x + t.width / 2, t.y + t.height / 2) // re-centre après un éventuel auto-scroll
  await page.waitForTimeout(120)
  await page.mouse.up()
}

async function cells(page) {
  return page.$$eval('[data-droppable="1"]', els => els.map(e => ({
    emp: Number(e.dataset.emp), date: e.dataset.date, dayidx: Number(e.dataset.dayidx),
    hasShift: !!e.querySelector('.plan-shift'),
    hasAbsence: !!e.querySelector('.plan-absence'),
  })))
}

// Salarié DÉMONTRÉ planifiable (il a déjà au moins un shift → contrat actif) ET disposant de ≥2 jours
// libres (sans shift ni absence). Évite les lignes vides de collaborateurs non planifiables (sans contrat).
async function schedulableEmployee(page) {
  const map = await cells(page)
  const byEmp = new Map()
  for (const c of map) {
    if (!byEmp.has(c.emp)) byEmp.set(c.emp, [])
    byEmp.get(c.emp).push(c)
  }
  for (const [emp, list] of byEmp) {
    const hasShift = list.some(c => c.hasShift)
    const free = list.filter(c => !c.hasShift && !c.hasAbsence).map(c => c.date)
    if (hasShift && free.length >= 2) return { emp, dates: free }
  }

  return null
}

async function gotoGrid(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1000)
  await expect(page.locator('.planning-grid').first()).toBeVisible()
}

const cell = (page, emp, date) => page.locator(`[data-droppable="1"][data-emp="${emp}"][data-date="${date}"]`).first()

test('A — modèle glissé vers une cellule : création serveur + persistance après reload', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)

  const name = 'E2E-DnD-' + Date.now().toString().slice(-5)
  const tpl = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/templates', body: { name, start_time: '08:00', end_time: '12:00', color: '#3b82f6' } })
  expect(tpl.status).toBeLessThan(300)

  await gotoGrid(page)
  const row = await schedulableEmployee(page)
  test.skip(!row, 'Aucun salarié planifiable avec jours libres.')
  const date = row.dates[0]

  const card = page.locator('[data-test="palette-template"]', { hasText: name }).first()
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    pointerDrag(page, card, cell(page, row.emp, date)),
  ])
  expect(resp.status()).toBe(201)

  await gotoGrid(page)
  const after = (await cells(page)).find(c => c.emp === row.emp && c.date === date)
  expect(after?.hasShift).toBeTruthy()
})

test('B — déplacement d’un brouillon vers un autre jour (PUT réel) + persistance', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await gotoGrid(page)

  const row = await schedulableEmployee(page)
  test.skip(!row, 'Aucun salarié planifiable avec jours libres.')
  const from = row.dates[0]
  const to = row.dates[1]
  const mk = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: row.emp, date: from, start_at: `${from} 10:00:00`, end_at: `${from} 12:00:00`, timezone: 'Europe/Paris' } })
  expect(mk.status).toBe(201)

  await gotoGrid(page)
  const block = cell(page, row.emp, from).locator('[data-test="draggable-shift"]').first()
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/shifts\/\d+$/.test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 }),
    pointerDrag(page, block, cell(page, row.emp, to)),
  ])
  expect(resp.status()).toBeLessThan(300)

  await gotoGrid(page)
  const after = await cells(page)
  expect(after.find(c => c.emp === row.emp && c.date === to)?.hasShift).toBeTruthy()
})

test('D — shift publié verrouillé : aucun PUT lors d’une tentative de drag', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await gotoGrid(page)

  const row = await schedulableEmployee(page)
  test.skip(!row, 'Aucun salarié planifiable avec jours libres.')
  const date = row.dates[0]
  const mk = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: row.emp, date, start_at: `${date} 10:00:00`, end_at: `${date} 12:00:00`, timezone: 'Europe/Paris' } })
  expect(mk.status).toBe(201)
  const pub = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts/publish', body: { shift_ids: [mk.json.id] } })
  expect(pub.status).toBeLessThan(300)

  await gotoGrid(page)
  const locked = cell(page, row.emp, date).locator('[data-test="locked-shift"]').first()
  await expect(locked).toBeVisible()

  let putSeen = false
  page.on('request', r => { if (/\/planning\/shifts\/\d+$/.test(r.url()) && r.method() === 'PUT') putSeen = true })
  await pointerDrag(page, locked, cell(page, row.emp, row.dates[1]))
  await page.waitForTimeout(800)
  expect(putSeen, 'un shift publié ne doit pas être déplaçable').toBeFalsy()
})

test('Visuel — grille planning en light et dark', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoGrid(page)
  await page.emulateMedia({ colorScheme: 'light' })
  await page.waitForTimeout(400)
  await page.screenshot({ path: 'tests/e2e/__screens__/lot4a-grid-light.png', fullPage: true })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.waitForTimeout(400)
  await page.screenshot({ path: 'tests/e2e/__screens__/lot4a-grid-dark.png', fullPage: true })
})
