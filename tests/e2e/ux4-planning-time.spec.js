// @ts-check
// ADR-779 (Company Experience 2.0 / UX4) — Planning & temps : parcours EXÉCUTÉS avec écritures réelles.
// Responsable (propriétaire) : créer un créneau par plage horaire, le modifier, le dupliquer, consulter
// les présences, demander puis approuver une absence, consulter un solde — sur une fiche CRÉÉE par
// l'exécution (registre ; le démontage la supprime avec ses créneaux, absences et soldes en cascade).
// Salarié (Bob, fiche de démonstration) : pointer, pause, reprise, fin — pointages inscrits au registre
// et supprimés par identifiant exact ; demande d'absence envoyée puis ANNULÉE par l'API métier
// (le journal des soldes est en ajout seul : la demande annulée reste, déclarée au checkpoint).
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'
import { createRun } from './run-ledger.js'

const OUT = 'docs/bmad/ux4-779'
const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

const watch = page => {
  const bad = []
  let at = ''
  page.on('response', r => {
    const url = r.url().replace(/^https?:\/\/[^/]+/, '')
    if (url.startsWith('/api/') && r.status() >= 400) bad.push({ at, status: r.status(), url: url.slice(0, 110) })
  })
  page.on('pageerror', e => bad.push({ at, js: String(e.message).slice(0, 160) }))

  return { bad, mark: v => { at = v } }
}
const push = async (page, to) => {
  await page.evaluate(async t => { const r = document.querySelector('#app').__vue_app__.config.globalProperties.$router; await r.push(t).catch(() => {}) }, to)
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
  await page.waitForTimeout(800)
}
const meter = async page => {
  await page.evaluate(() => {
    window.__ux = { clicks: 0, pages: [location.pathname], scroll: 0 }
    if (!window.__uxBound) {
      window.__uxBound = true
      document.addEventListener('click', () => { window.__ux.clicks++ }, true)
      document.querySelector('#app').__vue_app__.config.globalProperties.$router.afterEach(to => { if (window.__ux.pages.at(-1) !== to.path) window.__ux.pages.push(to.path) })
    }
  })

  return async (fields = 0) => page.evaluate(f => ({
    clicks: window.__ux.clicks, fields: f, pages: window.__ux.pages.length - 1,
    hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
  }), fields)
}
const setPicker = async (page, testid, d) => {
  const input = page.locator(`[data-test="${testid}"] input`).first()
  await input.waitFor({ state: 'attached', timeout: 10000 })
  await input.evaluate((el, v) => { el._flatpickr.setDate(v, true) }, d)
}
const pickOption = async (page, testid, text) => {
  await page.locator(`[data-test="${testid}"]`).click()
  const input = page.locator(`[data-test="${testid}"] input`).first()
  if (await input.isEditable().catch(() => false)) await input.fill(text)
  await page.locator('.v-overlay--active .v-list-item', { hasText: text }).first().click()
}
const setTheme = (page, v) => page.evaluate(t => { const s = document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('config'); if (s) s.theme = t }, v)
const shot = (page, name) => page.screenshot({ path: `${OUT}/${name}.jpg`, type: 'jpeg', quality: 55 })

test('UX4 responsable : créneau, absence, présences (propriétaire, registre d\'exécution)', async ({ page }) => {
  test.setTimeout(8 * 60 * 1000)
  page.setDefaultTimeout(25000)
  const run = createRun('ux4')
  const net = watch(page)
  const m = {}
  try {
    await loginCompany(page, 'owner@leezr.test')
    const companyId = await page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || ''))
    run.useCompany(companyId)

    // Préparation (API) : une personne SANS COMPTE de cette exécution, contrat actif 35 h, solde crédité.
    const last = run.name('Plan')
    const emp = await apiRequest(page, { method: 'POST', path: '/api/workforce/employees', body: { first_name: 'Inès', last_name: last, hire_date: '2026-01-05' } })
    const employeeId = emp.json.id ?? emp.json.data?.id
    run.track('employee', employeeId)
    const c = await apiRequest(page, { method: 'POST', path: `/api/workforce/employees/${employeeId}/contracts`, body: { contract_type: 'cdi', work_model_key: 'horaire', start_date: '2026-01-05', weekly_hours: 35 } })
    expect(c.status, 'contrat créé').toBe(201)
    const contractId = c.json.id ?? c.json.data?.id
    const act = await apiRequest(page, { method: 'POST', path: `/api/workforce/employees/${employeeId}/contracts/${contractId}/activate` })
    expect(act.status, `contrat activé (${JSON.stringify(act.json).slice(0, 160)})`).toBeLessThan(300)
    execFileSync('php', ['artisan', 'e2e:prepare-leave-fixture', `--employee=${employeeId}`, '--leave-type=1', '--days=10'], { encoding: 'utf8' })

    // ── 1. Créer un créneau en choisissant une plage horaire dans la vue Jour ─────────────────
    await push(page, '/company/workforce/planning')
    await page.locator('[data-test="time-space-planning"]').waitFor()
    await page.locator('[data-test="plan-view-calendar"]').click()
    await page.locator('.fc-timegrid-slot-lane[data-time="09:00:00"]').first().waitFor()
    let read = await meter(page)
    net.mark('créer créneau')
    await page.locator('.fc-timegrid-slot-lane[data-time="09:00:00"]').first().scrollIntoViewIfNeeded()
    await page.waitForTimeout(300)
    const a = await page.locator('.fc-timegrid-slot-lane[data-time="09:00:00"]').first().boundingBox()
    const b = await page.locator('.fc-timegrid-slot-lane[data-time="11:30:00"]').first().boundingBox()
    const x = a.x + a.width * 0.6
    await page.mouse.move(x, a.y + 4)
    await page.mouse.down()
    await page.mouse.move(x, a.y + 40, { steps: 5 })
    await page.mouse.move(x, b.y + b.height - 3, { steps: 8 })
    await page.mouse.up()
    await page.evaluate(() => { window.__ux.clicks++ }) // le glisser compte pour une action
    await expect(page.locator('[data-test="shift-start"] input'), 'heure de début préremplie').toHaveValue('09:00')
    await expect(page.locator('[data-test="shift-end"] input'), 'heure de fin préremplie').toHaveValue('12:00')
    await expect(page.locator('[data-test="shift-duration"]')).toContainText('3 h 00')
    const day = await page.locator('[data-test="shift-date"] input').first().inputValue()
    expect(day, 'date préremplie').toMatch(/^\d{4}-\d{2}-\d{2}$/)
    await pickOption(page, 'shift-employee', last)
    await shot(page, 'j-desktop-01-shift-range')
    const [created] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/planning\/shifts$/.test(r.url()) && r.request().method() === 'POST'),
      page.locator('[data-test="shift-submit"]').click(),
    ])
    expect(created.status(), `créneau créé (${(await created.text()).slice(0, 160)})`).toBe(201)
    const shiftId = (await created.json()).id
    m.creer_creneau = await read(1)

    // ── 2. Modifier ce créneau depuis le calendrier ─────────────────────────────────────────
    await page.waitForTimeout(1200)
    read = await meter(page)
    net.mark('modifier créneau')
    await page.locator('.fc-event', { hasText: /Inès|Ines/ }).first().click()
    await page.locator('[data-test="cal-shift-edit"]').click()
    await expect(page.locator('[data-test="shift-start"] input')).toHaveValue('09:00')
    await page.locator('[data-test="shift-end"] input').fill('12:30')
    const [upd] = await Promise.all([
      page.waitForResponse(r => new RegExp(`/api/workforce/planning/shifts/${shiftId}$`).test(r.url()) && r.request().method() === 'PUT'),
      page.locator('[data-test="shift-submit"]').click(),
    ])
    expect(upd.status(), 'créneau modifié').toBe(200)
    m.modifier_creneau = await read(1)

    // ── 3. Dupliquer (lendemain, mêmes heures) ─────────────────────────────────────────────
    await page.waitForTimeout(1200)
    read = await meter(page)
    net.mark('dupliquer')
    await page.locator('.fc-event', { hasText: /Inès|Ines/ }).first().click()
    await page.locator('[data-test="cal-shift-edit"]').click()
    await page.locator('[data-test="shift-duplicate"]').click()
    const next = new Date(`${day}T00:00:00`); next.setDate(next.getDate() + 1)
    await expect(page.locator('[data-test="shift-date"] input').first()).toHaveValue(iso(next))
    const [dup] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/planning\/shifts$/.test(r.url()) && r.request().method() === 'POST'),
      page.locator('[data-test="shift-submit"]').click(),
    ])
    expect(dup.status(), 'créneau dupliqué').toBe(201)
    m.dupliquer_creneau = await read(0)

    // ── 4. Consulter les présences (prévu / réalisé) ───────────────────────────────────────
    read = await meter(page)
    net.mark('présences')
    await page.locator('[data-test="time-space-time"]').click()
    await page.waitForURL(/\/company\/workforce\/time/)
    await page.waitForLoadState('networkidle').catch(() => {})
    await expect(page.locator('[data-test="asn-tab-cockpit"], [data-test^="cockpit-row-"]').first()).toBeVisible()
    m.consulter_presences = await read(0)
    await shot(page, 'j-desktop-02-time')

    // ── 5. Demander une absence pour la personne (durée calculée, solde affiché) ─────────────
    const mon = new Date('2026-12-07T00:00:00')
    mon.setDate(mon.getDate() + (parseInt(run.runId.slice(-2), 36) % 8) * 7) // semaine propre à l'exécution
    const tue = new Date(mon); tue.setDate(tue.getDate() + 1)
    read = await meter(page)
    net.mark('demander absence')
    await page.locator('[data-test="time-space-leave"]').click()
    await page.waitForURL(/\/company\/workforce\/leave/)
    await page.locator('[data-test="leave-new"]').click()
    await pickOption(page, 'leave-employee', last)
    await pickOption(page, 'leave-type', 'Congés payés')
    await setPicker(page, 'leave-from', iso(mon))
    await setPicker(page, 'leave-to', iso(tue))
    await expect(page.locator('[data-test="leave-duration"] input'), 'durée calculée par le moteur').toHaveValue('2')
    await expect(page.locator('[data-test="leave-balance"]'), 'solde affiché en jours').toContainText(/Solde disponible : [0-9]+(,[0-9]+)? jours/)
    await expect(page.locator('[data-test="leave-duration"]'), 'méthode affichée : estimation').toContainText(/Estimation : 2 jours/)
    // Correction manuelle autorisée (demi-journée) : c'est elle qui est envoyée et enregistrée.
    await page.locator('[data-test="leave-duration"] input').fill('1.5')
    await shot(page, 'j-desktop-03-leave-form')
    const [lr] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/leaves$/.test(r.url()) && r.request().method() === 'POST'),
      page.locator('[data-test="leave-submit"]').click(),
    ])
    expect(lr.status(), `absence demandée (${(await lr.text()).slice(0, 160)})`).toBe(201)
    const leave = await lr.json()
    expect(JSON.parse(lr.request().postData() || '{}').days_count_hundredths, 'correction manuelle transmise telle quelle').toBe(150)
    expect(leave.days_count_hundredths, 'valeur enregistrée = valeur affichée').toBe(150)
    m.demander_absence = await read(5)

    // ── 6. Approuver (la demande est résumée dans la confirmation) ───────────────────────────
    await page.waitForTimeout(1000)
    read = await meter(page)
    net.mark('approuver')
    await page.locator(`[data-test="leave-approve-${leave.id}"]`).click()
    await expect(page.locator('[data-test="leave-confirm-summary"]')).toContainText('1,5 jour')
    const [ap] = await Promise.all([
      page.waitForResponse(r => new RegExp(`/api/workforce/leaves/${leave.id}/approve$`).test(r.url())),
      page.locator('[data-test="leave-confirm"]').click(),
    ])
    expect(ap.status(), 'absence approuvée').toBe(200)
    m.approuver_absence = await read(0)

    // ── 7. Consulter un solde ──────────────────────────────────────────────────────────────
    read = await meter(page)
    net.mark('solde')
    await page.getByRole('tab', { name: /Soldes/ }).first().click()
    await page.waitForTimeout(800)
    m.consulter_solde = await read(0)

    // ── 8. Identifier une anomalie (cockpit : écarts prévu/réel) ────────────────────────────
    read = await meter(page)
    net.mark('anomalie')
    await page.locator('[data-test="time-space-time"]').click()
    await page.waitForURL(/\/company\/workforce\/time/)
    await page.waitForLoadState('networkidle').catch(() => {})
    m.identifier_anomalie = await read(0)
    m.anomalies_visibles = await page.locator('[data-test^="cockpit-row-"]').count()

    // ── Durées lisibles : aucune unité technique dans l'espace ──────────────────────────────
    for (const p of ['/company/workforce/planning', '/company/workforce/time', '/company/workforce/leave']) {
      await push(page, p)
      const txt = await page.locator('main').innerText()
      expect(txt, `${p} sans unité technique`).not.toMatch(/centi[eè]me|\(centimes\)|\d+\.\d+h\b/i)
    }
  }
  finally {
    fs.writeFileSync(`${OUT}/measures-manager.json`, JSON.stringify({ measures: m, errors: net.bad }, null, 2))
    fs.writeFileSync(`${OUT}/teardown-manager.json`, JSON.stringify(run.teardown(), null, 2))
  }
  expect(net.bad.filter(b => b.js), 'aucune erreur JS').toEqual([])
})

test('UX4 salariée mobile : consulter, pointer, pause, reprise, fin (Alice, administratrice également salariée)', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  page.setDefaultTimeout(25000)
  await page.setViewportSize({ width: 390, height: 844 })
  const run = createRun('ux4clock')
  const net = watch(page)
  const m = {}
  try {
    await loginCompany(page, 'admin@leezr.test')
    run.useCompany(await page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || '')))
    await push(page, '/company')
    let read = await meter(page)
    net.mark('mon planning')
    await push(page, '/company/workforce/me')
    await expect(page.locator('[data-test="me-planning"]')).toBeVisible()
    m.consulter_mon_planning = await read(0)
    await shot(page, 'j-mobile-01-me')
    expect(await page.evaluate(() => document.documentElement.scrollWidth), 'aucun défilement horizontal').toBeLessThanOrEqual(390)

    const me = await apiRequest(page, { path: '/api/workforce/me' })
    test.skip(!!me.json.today_clock || !!me.json.open_entry, 'déjà un pointage : la recette ne touche pas des données qu\'elle n\'a pas créées')
    read = await meter(page)
    net.mark('pointage')
    const [ci] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/me\/clock-in$/.test(r.url())),
      page.locator('[data-test="me-clock-in"]').click(),
    ])
    expect(ci.status(), `début de journée (${(await ci.text()).slice(0, 160)})`).toBe(201)
    run.track('time_entry', (await ci.json()).id)
    await page.locator('[data-test="me-start-break"]').click()
    await expect(page.locator('[data-test="me-break-panel"]')).toBeVisible()
    await shot(page, 'j-mobile-02-break')
    await page.locator('[data-test="me-end-break"]').click()
    await expect(page.locator('[data-test="me-clock-out"]')).toBeVisible()
    const [co] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/me\/clock-out$/.test(r.url())),
      page.locator('[data-test="me-clock-out"]').click(),
    ])
    expect(co.status(), 'fin de journée').toBeLessThan(300)
    m.pointer_pause_reprise_fin = await read(0)
    await shot(page, 'j-mobile-03-clock-done')
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390)
  }
  finally {
    fs.writeFileSync(`${OUT}/measures-clock.json`, JSON.stringify({ measures: m, errors: net.bad }, null, 2))
    fs.writeFileSync(`${OUT}/teardown-clock.json`, JSON.stringify(run.teardown(), null, 2))
  }
  expect(net.bad.filter(b => b.js), 'aucune erreur JS').toEqual([])
})

test('UX4 salarié mobile : pointage ouvert expliqué, demander une absence (Bob)', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  page.setDefaultTimeout(25000)
  await page.setViewportSize({ width: 390, height: 844 })
  const run = createRun('ux4bob')
  const net = watch(page)
  const m = {}
  let leaveId = null
  try {
    await loginCompany(page, 'bob@leezr.test')
    run.useCompany(await page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || '')))
    await push(page, '/company/workforce/me')
    const me = await apiRequest(page, { path: '/api/workforce/me' })
    if (me.json.open_entry && !me.json.today_clock) {
      // Pointage d'un autre jour resté ouvert : explication, pas de « Commencer » voué au refus.
      await expect(page.locator('[data-test="me-open-entry"]')).toBeVisible()
      await expect(page.locator('[data-test="me-clock-in"]')).toHaveCount(0)
      m.pointage_ouvert_explique = true
      await shot(page, 'j-mobile-04-open-entry')
    }

    const d = new Date('2027-02-01T00:00:00')
    d.setDate(d.getDate() + (parseInt(run.runId.slice(-3), 36) % 40) * 7) // lundi propre à l'exécution
    const e = new Date(d); e.setDate(e.getDate() + 2)
    const read = await meter(page)
    net.mark('absence salarié')
    await page.locator('[data-test="me-request-leave"]').click()
    await page.locator('[data-test="me-leave-from"] input').fill(iso(d))
    await page.locator('[data-test="me-leave-to"] input').fill(iso(e))
    await expect(page.locator('[data-test="me-leave-days"] input'), 'durée calculée (3 jours)').toHaveValue('3')
    await expect(page.locator('[data-test="me-leave-balance"]')).toContainText(/Solde disponible/)
    await expect(page.locator('[data-test="me-leave-days"]'), 'calcul affiché').toContainText(/Estimation : /)
    await page.waitForTimeout(400)
    await shot(page, 'j-mobile-05-leave')
    await page.locator('[data-test="me-leave-reason"] textarea').fill(`Recette ${run.runId}`)
    const [sl] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/me\/leaves$/.test(r.url()) && r.request().method() === 'POST'),
      page.locator('[data-test="me-leave-submit"]').click(),
    ])
    expect(sl.status(), `absence demandée (${(await sl.text()).slice(0, 160)})`).toBe(201)
    leaveId = (await sl.json()).id
    expect(JSON.parse(sl.request().postData() || '{}').days_count_hundredths, 'centièmes exacts').toBe(300)
    m.demander_absence_salarie = await read(3)
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390)
  }
  finally {
    // Annulation MÉTIER par le propriétaire : le journal des soldes n'est jamais réécrit.
    if (leaveId) {
      await page.context().clearCookies()
      await loginCompany(page, 'owner@leezr.test')
      const cancel = await apiRequest(page, { method: 'POST', path: `/api/workforce/leaves/${leaveId}/cancel` })
      m.annulation_demande = { id: leaveId, status: cancel.status }
    }
    fs.writeFileSync(`${OUT}/measures-employee.json`, JSON.stringify({ measures: m, errors: net.bad }, null, 2))
    fs.writeFileSync(`${OUT}/teardown-employee.json`, JSON.stringify(run.teardown(), null, 2))
  }
  expect(net.bad.filter(b => b.js), 'aucune erreur JS').toEqual([])
})

// ── Profils (lecture) : vues visibles, aucune page de supervision vide, aucun refus réseau ──────
const PROFILES = ['owner', 'admin', 'bob', 'salarie', 'manager', 'rh-nopay', 'consultation', 'pay']
test('UX4 profils : vues de l\'espace selon les droits réels', async ({ page }) => {
  test.setTimeout(8 * 60 * 1000)
  const result = {}
  for (const who of PROFILES) {
    await page.context().clearCookies()
    const net = watch(page)
    await loginCompany(page, `${who}@leezr.test`)
    const nav = (await apiRequest(page, { path: '/api/nav' })).json
    const time = (nav.groups || []).find(g => g.key === 'time')
    const entries = (time?.items || time?.children || []).map(i => i.key)
    const views = {}
    for (const [k, p] of [['planning', '/company/workforce/planning'], ['time', '/company/workforce/time'], ['leave', '/company/workforce/leave'], ['me', '/company/workforce/me']]) {
      await page.goto(p, { waitUntil: 'networkidle' })
      await page.waitForTimeout(900)
      const landed = new URL(page.url()).pathname
      const tabs = await page.locator('[data-test^="time-space-"]:not([data-test="time-space-views"])').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('time-space-', '')))
      views[k] = { landed, allowed: landed === p, tabs }
    }
    await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
    await page.waitForTimeout(800)
    await shot(page, `hub-${who}`)
    result[who] = { navEntries: entries, views, errors: net.bad.filter(b => b.js || b.status >= 500 || b.status === 403) }
  }
  fs.writeFileSync(`${OUT}/result-profiles.json`, JSON.stringify(result, null, 1))
  for (const [who, r] of Object.entries(result)) {
    expect(r.errors, `${who} : ni erreur JS, ni 403, ni 5xx`).toEqual([])
    for (const v of Object.values(r.views)) {
      // Toute vue proposée dans l'en-tête est une vue réellement accessible.
      for (const t of v.allowed ? v.tabs : []) expect(r.views[t]?.allowed, `${who} : vue « ${t} » proposée mais refusée`).toBeTruthy()
    }
  }
})

test('UX4 entreprise sans Workforce : aucun espace Planning & temps, aucun écran cassé', async ({ page }) => {
  const net = watch(page)
  await loginCompany(page, 'owner@leezr.test')
  const companies = (await apiRequest(page, { path: '/api/my-companies' })).json
  const list = companies.companies || companies.data || companies
  const other = list.find(c => /Trial Logistics/.test(c.name))
  test.skip(!other, 'pas d\'entreprise sans Workforce pour ce compte')
  await page.evaluate(id => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').switchCompany(id), other.id)
  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForSelector('.layout-vertical-nav .nav-link', { timeout: 20000 })
  const groups = (await apiRequest(page, { path: '/api/nav' })).json.groups.map(g => g.key)
  expect(groups).not.toContain('time')
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  expect(new URL(page.url()).pathname, 'ancien lien : pas d\'écran Planning sans le module').not.toBe('/company/workforce/planning')
  await shot(page, 'no-workforce-planning-link')
  const home = list.find(c => !/Trial Logistics|M6E LOAD/.test(c.name))
  await page.evaluate(id => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').switchCompany(id), home.id)
  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)
  expect(net.bad.filter(b => b.js), 'aucune erreur JS').toEqual([])
})

test('UX4 captures : tablette, smartphone, sombre ; anciennes routes', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const net = watch(page)
  await loginCompany(page, 'owner@leezr.test')
  for (const [label, vp] of [['tablet', { width: 820, height: 1180 }], ['mobile', { width: 390, height: 844 }], ['desktop', { width: 1440, height: 900 }]]) {
    await page.setViewportSize(vp)
    for (const [k, p] of [['planning', '/company/workforce/planning'], ['time', '/company/workforce/time'], ['leave', '/company/workforce/leave']]) {
      await page.goto(p, { waitUntil: 'networkidle' })
      await page.waitForTimeout(1200)
      expect(await page.evaluate(() => document.documentElement.scrollWidth), `${label} ${k} : aucun défilement horizontal`).toBeLessThanOrEqual(vp.width)
      await shot(page, `j-${label}-${k}`)
    }
  }
  // Thème sombre (même geste que le sélecteur de thème : cookie de configuration).
  await page.setViewportSize({ width: 1440, height: 900 })
  await setTheme(page, 'dark')
  await page.waitForTimeout(500)
  for (const [k, p] of [['planning', '/company/workforce/planning'], ['leave', '/company/workforce/leave']]) {
    await page.goto(p, { waitUntil: 'networkidle' })
    await page.waitForTimeout(1200)
    await shot(page, `j-dark-${k}`)
  }
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)
  await shot(page, 'j-dark-mobile-me')
  await setTheme(page, 'light')
  await page.waitForTimeout(500)
  // Anciennes routes et liens de l'accueil : toujours valides.
  for (const p of ['/company/workforce/time?tab=entries', '/company/workforce/leave', '/company/workforce/planning']) {
    await page.goto(p, { waitUntil: 'networkidle' })
    expect(new URL(page.url()).pathname).toBe(p.split('?')[0])
  }
  expect(net.bad.filter(b => b.js || b.status >= 500), 'aucune erreur').toEqual([])
})
