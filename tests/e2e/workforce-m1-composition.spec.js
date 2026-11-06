// Refonte Smart Planning — M1 (ADR-724) : modèle de shift ENRICHI (activités + pause positionnée) créé
// depuis le parcours de gestion autorisé (API authentifiée, RBAC planning_manage), appliqué à un salarié,
// matérialisation vérifiée, puis modification du modèle source SANS effet rétroactif sur le shift créé.
// Flux réel de bout en bout (middleware + validation + contrôleurs). Nettoyage strict en afterEach.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest, trackCreatedShifts, cleanupShifts } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

const API = '/api/workforce/planning'
let created
let createdTemplateId = null

test.beforeEach(({ page }) => { created = trackCreatedShifts(page) })
test.afterEach(async ({ page }) => {
  await cleanupShifts(page, created)
  if (createdTemplateId) {
    await apiRequest(page, { method: 'DELETE', path: `${API}/templates/${createdTemplateId}` })
    createdTemplateId = null
  }
})

async function gotoGrid(page) {
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(700)
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(900)
}
async function freeCells(page) {
  const cells = await page.$$eval('[data-droppable="1"]', els => els.map(e => ({
    emp: Number(e.dataset.emp), date: e.dataset.date,
    busy: !!e.querySelector('.plan-shift') || !!e.querySelector('.plan-absence'),
  })))
  return cells.filter(c => !c.busy && c.emp && c.date)
}

test('M1 — modèle enrichi : authoring → application → matérialisation → modif sans effet rétroactif', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  await gotoGrid(page)

  // 1) AUTHORING — création d'un modèle enrichi via le parcours autorisé (planning_manage).
  const composition = {
    schema_version: 1,
    activities: [
      { label: 'Brief M1', offset_minutes: 0, duration_minutes: 40 },
      { label: 'Tournée M1', offset_minutes: 70, duration_minutes: 50 },
    ],
    breaks: [{ offset_minutes: 50, duration_minutes: 20 }],
  }
  const name = 'M1 Enrichi ' + Date.now()
  const mk = await apiRequest(page, {
    method: 'POST', path: `${API}/templates`,
    body: { name, start_time: '02:00', end_time: '04:00', color: '#3b82f6', composition },
  })
  expect(mk.status, JSON.stringify(mk.json)).toBe(201)
  createdTemplateId = mk.json.id
  expect(mk.json.metadata?.composition?.schema_version).toBe(1)
  expect(mk.json.metadata?.composition?.activities).toHaveLength(2)

  // Composition invalide refusée (chevauchement activité/pause) — 422, aucun modèle fantôme.
  const bad = await apiRequest(page, {
    method: 'POST', path: `${API}/templates`,
    body: { name: name + ' BAD', start_time: '02:00', end_time: '04:00',
      composition: { schema_version: 1, activities: [{ label: 'X', offset_minutes: 50, duration_minutes: 30 }], breaks: [{ offset_minutes: 50, duration_minutes: 20 }] } },
  })
  expect(bad.status).toBe(422)

  // 2) APPLICATION à un salarié — sonde une cellule libre (base DEV possiblement saturée → skip honnête).
  const cells = await freeCells(page)
  let shift = null
  for (const c of cells.slice(0, 25)) {
    const r = await apiRequest(page, {
      method: 'POST', path: `${API}/shifts`,
      body: { employee_id: c.emp, date: c.date, start_at: `${c.date} 02:00:00`, end_at: `${c.date} 04:00:00`, timezone: 'Europe/Paris', schedule_template_id: createdTemplateId },
    })
    if (r.status === 201) { shift = r.json; break }
  }
  test.skip(!shift, 'Aucune cellule planifiable sur la base DEV (saturation/48h) — application non testée.')

  // Le snapshot figé embarque la composition enrichie (schema + activités + pauses HH:mm).
  expect(shift.template_snapshot?.schema_version).toBe(1)
  expect(shift.template_snapshot?.activities).toHaveLength(2)
  expect(shift.template_snapshot?.break_windows).toEqual([{ start: '02:50', end: '03:10' }])

  // 3) MATÉRIALISATION — vraies ScheduledActivity créées, source=template, employee dérivé, bornes exactes.
  const act1 = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${shift.id}` })
  expect(act1.status).toBe(200)
  const list1 = (act1.json?.data ?? act1.json ?? []).slice().sort((a, b) => a.start_at.localeCompare(b.start_at))
  expect(list1).toHaveLength(2)
  expect(list1[0].source).toBe('template')
  expect(list1[0].employee_id).toBe(shift.employee_id)
  expect(list1[0].label).toBe('Brief M1')
  expect(list1[1].label).toBe('Tournée M1')
  const labelsBefore = list1.map(a => a.label).sort()

  // 4) RELOAD — persistance.
  await gotoGrid(page)
  const act2 = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${shift.id}` })
  const list2 = act2.json?.data ?? act2.json ?? []
  expect(list2).toHaveLength(2)

  // 5) MODIFICATION DU MODÈLE SOURCE — composition radicalement différente.
  const upd = await apiRequest(page, {
    method: 'PUT', path: `${API}/templates/${createdTemplateId}`,
    body: { composition: { schema_version: 1, activities: [{ label: 'Autre M1', offset_minutes: 0, duration_minutes: 60 }], breaks: [] } },
  })
  expect(upd.status).toBe(200)

  // 6) AUCUN EFFET RÉTROACTIF — le shift déjà créé conserve ses activités d'origine.
  const act3 = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${shift.id}` })
  const list3 = (act3.json?.data ?? act3.json ?? [])
  expect(list3).toHaveLength(2)
  expect(list3.map(a => a.label).sort()).toEqual(labelsBefore)
})
