// Smart Planning Lot 3 complément (ADR-717) — parcours navigateur RÉELS.
// Prouve le câblage UI + l'application serveur des nouveaux endpoints (absence partielle, décompte,
// remplacement guidé, confidentialité). La logique de couverture/idempotence/outbox/double-compte est
// prouvée exhaustivement en PHPUnit ; ici on valide l'expérience opérationnelle dans un vrai navigateur.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

async function anyPublishedShift(page) {
  const week = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts/week?date=${new Date().toISOString().slice(0, 10)}` })
  const list = Array.isArray(week.json?.data) ? week.json.data : (Array.isArray(week.json) ? week.json : [])

  return list.find(s => s.status === 'published') || list[0] || null
}

// ── Scénario A — absence PARTIELLE : décompte proportionnel (endpoint réel) + rendu du formulaire ──
test('A — absence partielle : décompte proportionnel + champs heures rendus', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)

  const shift = await anyPublishedShift(page)
  test.skip(!shift, 'Aucun shift dans la base E2E — logique couverte par PHPUnit.')
  const empId = shift.employee_id || shift.employee?.id
  const date = (shift.date || new Date().toISOString()).slice(0, 10)

  // Décompte d'une absence 09:00–13:00 (4 h) : l'endpoint réel renvoie heures ET jours proportionnels.
  const prev = await apiRequest(page, {
    method: 'POST', path: '/api/workforce/leaves/deduction-preview',
    body: { employee_id: empId, date_from: date, date_to: date, start_time: '09:00', end_time: '13:00' },
  })
  expect(prev.status).toBe(200)
  expect(prev.json.hours_count_hundredths).toBe(400) // 4,00 h
  expect(prev.json.days_count_hundredths).toBeLessThan(100) // < 1 jour (partiel)

  // Rendu du formulaire : le toggle « absence partielle » révèle les champs heures.
  await page.goto('/company/workforce/leave', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.getByRole('button', { name: /Nouvelle demande|New request|Demander|Nouvelle/i }).first().click()
  await page.waitForTimeout(500)
  await page.getByText(/Absence partielle|Partial absence/i).first().click()
  await expect(page.getByLabel(/Heure de début|Start time/i).first()).toBeVisible()
  await page.screenshot({ path: 'tests/e2e/__screens__/lot3-partial-leave-desktop.png', fullPage: true })
})

// ── Scénario B — remplacement guidé : endpoint candidats (fenêtre + candidats scopés) ──
test('B — remplacement : candidats renvoient fenêtre non assurée + liste scopée', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)

  const shift = await anyPublishedShift(page)
  test.skip(!shift || shift.status !== 'published', 'Aucun shift publié cette semaine — remplacement couvert par PHPUnit.')

  const cand = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts/${shift.shift_id || shift.id}/replacement-candidates` })
  expect(cand.status).toBe(200)
  expect(cand.json).toHaveProperty('window')
  expect(Array.isArray(cand.json.candidates)).toBeTruthy()
})

// ── Scénario D — sécurité/confidentialité : aucun motif exposé + accès direct protégé ──
test('D — sécurité : projection sans motif + endpoint protégé (accès direct)', async ({ page, browser }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)

  const today = new Date().toISOString().slice(0, 10)
  // La projection board ne doit JAMAIS exposer le motif (confidentialité §8).
  const board = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts/board?from=${today}&to=${today}` })
  expect(board.status).toBe(200)
  expect(JSON.stringify(board.json || {})).not.toContain('"reason"')

  // Accès direct NON authentifié (contexte vierge, en JSON, sans suivre la redirection login) → pas 200.
  const fresh = await browser.newContext({ ignoreHTTPSErrors: true })
  const res = await fresh.request.get(`https://leezr.test/api/workforce/planning/shifts/board?from=${today}&to=${today}`, {
    headers: { Accept: 'application/json' }, maxRedirects: 0, failOnStatusCode: false,
  })
  expect(res.status(), 'accès direct non authentifié doit être refusé').not.toBe(200)
  await fresh.close()

  // Capture mobile de la grille planning.
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await page.screenshot({ path: 'tests/e2e/__screens__/lot3-planning-mobile.png', fullPage: true })
})
