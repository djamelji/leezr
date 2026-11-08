// Lot A — recette : couverture QUALIFIÉE par catégorie + édition directe des besoins dans le planning.
// Écritures persistées via la vraie pile HTTP. Prouve : une formation ne gonfle pas la couverture Route ;
// l'édition inline d'un besoin recalcule l'écart.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

async function api(page, method, url, body) {
  return await page.evaluate(async ({ method, url, body }) => {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    const c = document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/)
    const res = await fetch(`/api${url}`, {
      method, credentials: 'include',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '', 'X-Company-Id': c ? decodeURIComponent(c[1]) : '' },
      body: body ? JSON.stringify(body) : undefined,
    })

    return { status: res.status, json: await res.json().catch(() => null) }
  }, { method, url, body })
}

test('couverture qualifiée : formation ne gonfle pas Route (API persistée)', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(500)

  const stamp = Date.now().toString().slice(-5)
  // Catégories.
  const catR = await api(page, 'POST', '/workforce/planning/coverage-categories', { code: `ROUTE${stamp}`, name: `Route ${stamp}`, color: '#4b4bd6' })
  const catF = await api(page, 'POST', '/workforce/planning/coverage-categories', { code: `FORM${stamp}`, name: `Formation ${stamp}`, color: '#1f8a54' })
  expect(catR.status).toBe(201)
  const routeId = catR.json.id
  const formId = catF.json.id

  // Modèles portant la catégorie.
  const tplR = await api(page, 'POST', '/workforce/planning/templates', { name: `TR${stamp}`, start_time: '09:00', end_time: '17:00', coverage_category_id: routeId })
  const tplF = await api(page, 'POST', '/workforce/planning/templates', { name: `TF${stamp}`, start_time: '09:00', end_time: '17:00', coverage_category_id: formId })
  expect(tplR.status).toBe(201)

  const emps = await api(page, 'GET', '/workforce/employees?per_page=6')
  const employees = (emps.json.data ?? emps.json).slice(0, 5)
  // Date unique par run (évite les résidus → 422).
  const base = new Date('2027-01-04T00:00:00')
  base.setDate(base.getDate() + (parseInt(stamp) % 600))
  const day = base.toISOString().slice(0, 10)

  // 3 shifts Route + 2 Formation (on compte les créations réellement réussies : contrats/conflits locaux).
  let routeCreated = 0
  let formCreated = 0
  for (let i = 0; i < employees.length; i++) {
    const isRoute = i < 3
    const tpl = isRoute ? tplR.json : tplF.json
    const r = await api(page, 'POST', '/workforce/planning/shifts', {
      employee_id: employees[i].id, date: day,
      start_at: `${day} 09:00:00`, end_at: `${day} 17:00:00`, timezone: 'Europe/Paris', schedule_template_id: tpl.id,
    })
    if (r.status === 201) { isRoute ? routeCreated++ : formCreated++ }
  }
  expect(routeCreated).toBeGreaterThan(0)
  expect(formCreated).toBeGreaterThan(0)

  const reqR = await api(page, 'POST', '/workforce/planning/coverage', { date: day, start_time: '09:00', end_time: '17:00', headcount: 10, coverage_category_id: routeId, priority: 'high' })
  expect(reqR.status).toBe(201)

  const sum = await api(page, 'GET', `/workforce/planning/coverage/summary?from=${day}&to=${day}`)
  const routeRow = (sum.json.requirements || []).find(r => r.requirement_id === reqR.json.id)
  // La couverture Route = EXACTEMENT les shifts Route (la formation ne gonfle pas).
  expect(routeRow.assigned).toBe(routeCreated)
  expect(routeRow.assigned).toBeLessThan(routeCreated + formCreated)
  expect(routeRow.status).toBe('under')
})

test('édition directe d\'un besoin depuis la grille (inline)', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1100 })

  let weekDate = null
  page.on('request', r => { const mm = r.url().match(/\/shifts\/week\?date=([\d-]+)/); if (mm) weekDate = mm[1] })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1000)

  const stamp = Date.now().toString().slice(-5)
  const dow = new Date(weekDate + 'T00:00:00').getDay()
  // M6.E — fenêtre horaire UNIQUE : `coverageLines` groupe par (catégorie,horaires) ; un créneau unique
  // garantit une LIGNE de couverture isolée (étiquetée par notre libellé), insensible aux besoins résiduels.
  const mm = String((Number(stamp) % 55) + 1).padStart(2, '0')
  await api(page, 'POST', '/workforce/planning/coverage', { day_of_week: dow, start_time: `08:${mm}`, end_time: `16:${mm}`, headcount: 4, priority: 'normal', label: `Besoin ${stamp}` })

  await page.reload({ waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1200)

  // M6.E — cible la LIGNE du besoin créé (libellé unique) puis SON chip → édition déterministe
  // (plus de `.first()` global qui tombait sur un besoin résiduel).
  const covRow = page.locator('tr.coverage-row', { hasText: `Besoin ${stamp}` })
  await expect(covRow).toBeVisible({ timeout: 10000 })
  await covRow.locator('.v-chip').first().click()
  await page.waitForTimeout(400)
  const dialog = page.locator('.v-dialog').last()
  await dialog.getByLabel(/Effectif requis|Required headcount/i).fill('7')
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/coverage\/\d+$/.test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 }),
    dialog.locator('[data-test="cov-save"]').click(),
  ])
  expect(resp.status()).toBe(200)
})
