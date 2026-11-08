// @ts-check
// Centralized E2E helpers — real Sanctum SPA flows (no CSRF bypass, no permission bypass).
import { expect } from '@playwright/test'

export async function loginCompany(page, email = 'owner@leezr.test', password = 'password') {
  // Robust login (same rationale as loginPlatform): wait for the real POST /login result,
  // retry past a transient throttle (429)/5xx, and never silently proceed unauthenticated.
  for (let attempt = 0; attempt < 3; attempt++) {
    await page.goto('/login', { waitUntil: 'networkidle' })
    await page.fill('input[type="email"], input[name="email"]', email)
    await page.fill('input[type="password"], input[name="password"]', password)

    const [resp] = await Promise.all([
      page.waitForResponse(r => r.url().includes('/api/login') && r.request().method() === 'POST', { timeout: 20000 }).catch(() => null),
      page.keyboard.press('Enter'),
    ])
    await page.waitForURL(u => !u.pathname.endsWith('/login'), { timeout: 20000 }).catch(() => {})
    await page.waitForTimeout(1500)

    if (!page.url().endsWith('/login')) return
    const status = resp ? resp.status() : 0
    if (status === 429 || status >= 500 || status === 0) {
      await page.waitForTimeout(20000)
      continue
    }
    throw new Error(`Company login failed for ${email} (login POST status ${status})`)
  }
  throw new Error(`Company login did not complete for ${email} after retries`)
}

export async function loginPlatform(page, email = 'admin@leezr.com', password = 'password') {
  // Robust login: submit, wait for the real POST /login result, and retry once past a
  // transient throttle (429) / 5xx. Never silently proceed unauthenticated (would mask failures).
  for (let attempt = 0; attempt < 3; attempt++) {
    await page.goto('/platform/login', { waitUntil: 'networkidle' })
    await page.fill('input[type="email"], input[name="email"]', email)
    await page.fill('input[type="password"], input[name="password"]', password)

    const [resp] = await Promise.all([
      page.waitForResponse(r => r.url().includes('/platform/login') && r.request().method() === 'POST', { timeout: 20000 }).catch(() => null),
      page.keyboard.press('Enter'),
    ])
    await page.waitForURL(u => !u.pathname.endsWith('/login'), { timeout: 20000 }).catch(() => {})
    await page.waitForTimeout(1500)

    if (!page.url().endsWith('/login')) return // off the login page → authenticated
    const status = resp ? resp.status() : 0
    if (status === 429 || status >= 500 || status === 0) {
      await page.waitForTimeout(20000) // ride out the 60s login throttle window, then retry
      continue
    }
    throw new Error(`Platform login failed for ${email} (login POST status ${status})`)
  }
  throw new Error(`Platform login did not complete for ${email} after retries`)
}

// Click an action and assert the triggered API call succeeds (real integration).
export async function clickAndExpectApi(page, { urlIncludes, method }, action) {
  const [resp] = await Promise.all([
    page.waitForResponse(r => r.url().includes(urlIncludes) && r.request().method() === method, { timeout: 20000 }),
    action(),
  ])
  expect(resp.status(), `${method} ${urlIncludes} → ${resp.status()}`).toBeLessThan(400)
  return resp
}

// Call an API with the browser's own authenticated session (cookie + XSRF), exactly as the SPA.
// Probes what the API ACTUALLY enforces. Retries past transient 0/401/429 so only a stable
// status is used — a transient is tolerated only if a definitive status is eventually obtained.
export async function apiRequest(page, { method = 'GET', path, body = null }) {
  const once = () => page.evaluate(async ({ m, p, b }) => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    try {
      const res = await fetch(p, {
        method: m,
        headers: {
          'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''),
          'X-Company-Id': decodeURIComponent(get('currentCompanyId') || ''),
          Accept: 'application/json',
          ...(b ? { 'Content-Type': 'application/json' } : {}),
        },
        credentials: 'include',
        ...(b ? { body: JSON.stringify(b) } : {}),
      })
      let json = null
      try { json = await res.json() } catch { /* no body */ }

      return { status: res.status, json }
    } catch {
      return { status: 0, json: null }
    }
  }, { m: method, p: path, b: body })

  let out = { status: 0, json: null }
  for (let i = 0; i < 6; i++) {
    out = await once()
    if (out.status !== 0 && out.status !== 401 && out.status !== 429) break
    await page.waitForTimeout(1200)
  }

  return out
}

// ── Hygiène E2E (ADR-720 §9) — isolation : capturer puis NETTOYER les shifts créés par un test, afin
// d'éviter toute accumulation entre runs (ne purge QUE ce que le test a créé, jamais les données tierces).
export function trackCreatedShifts(page) {
  const ids = new Set()
  page.on('response', async r => {
    try {
      const u = r.url(); const m = r.request().method()
      if (/\/planning\/shifts$/.test(u) && m === 'POST' && r.status() === 201) {
        const b = await r.json(); if (b?.id) ids.add(b.id)
      } else if (/\/planning\/shifts\/bulk-template$/.test(u) && m === 'POST' && r.status() === 201) {
        const b = await r.json(); (b?.created || []).forEach(id => ids.add(id))
      } else if (/\/planning\/shifts\/\d+\/replace$/.test(u) && m === 'POST' && r.status() === 201) {
        const b = await r.json(); if (b?.replacement?.id) ids.add(b.replacement.id)
      }
    } catch { /* corps non-JSON */ }
  })

  return ids
}

// Supprime les brouillons créés (et annule les publiés) — appelé en afterEach pour laisser la base propre.
export async function cleanupShifts(page, ids) {
  for (const id of ids) {
    const del = await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${id}` })
    if (del.status >= 400) {
      // Publié → non supprimable : on annule (exclu de la couverture et du cumul 48h).
      await apiRequest(page, { method: 'POST', path: `/api/workforce/planning/shifts/${id}/cancel`, body: { reason: 'e2e cleanup' } })
    }
  }
  ids.clear()
}

// ── M6.E (ADR-736) — fiabilisation E2E : préconditions déterministes, indépendantes des données partagées.
// Nettoie les shifts (brouillons) + leurs activités d'un employé sur une plage → point de départ connu.
export async function cleanEmployeeRange(page, employeeId, from, to) {
  const list = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts?from=${from}&to=${to}&employee_id=${employeeId}&per_page=100` })
  let shifts = list.json
  if (shifts && !Array.isArray(shifts)) shifts = shifts.data ?? shifts.shifts ?? []
  if (!Array.isArray(shifts)) shifts = []
  for (const s of shifts) {
    const acts = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/activities?shift_id=${s.id}` })
    for (const a of (acts.json ?? [])) await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/activities/${a.id}` })
    if (s.status === 'draft') await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${s.id}` })
    else await apiRequest(page, { method: 'POST', path: `/api/workforce/planning/shifts/${s.id}/cancel`, body: { reason: 'e2e precondition' } })
  }
}

// Isole UNE ligne de la grille via la recherche (→ `.first()` déterministe, plus de dépendance à l'ordre).
export async function isolateInGrid(page, name) {
  const search = page.locator('[data-test="grid-search"] input').first()
  await search.waitFor({ state: 'visible', timeout: 10000 })
  await search.fill(name)
  await page.waitForTimeout(500)
}

// Premier employé disposant d'un contrat (créable → a de la capacité). Renvoie { id, name }.
export async function firstEmployee(page) {
  const emp = await apiRequest(page, { method: 'GET', path: '/api/workforce/employees?per_page=1' })
  const e = (emp.json?.data ?? emp.json ?? [])[0]

  return e ? { id: e.id, name: `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim() } : null
}

// Employé RÉELLEMENT capable d'accueillir un shift [start,end] le `date` donné — contrat actif, pas
// d'absence, pas d'overlap ET conforme aux règles temps de travail (durée/repos). On sonde avec LA FENÊTRE
// RÉELLE (create/delete) pour ne pas confondre « capable » et « règle métier qui rejette une durée ».
// Nettoie d'abord la journée de chaque candidat. Renvoie { id, name } ou null.
export async function capableEmployee(page, date, start = '09:00:00', end = '12:00:00') {
  const emp = await apiRequest(page, { method: 'GET', path: '/api/workforce/employees?per_page=50' })
  const list = emp.json?.data ?? emp.json ?? []
  for (const e of list) {
    await cleanEmployeeRange(page, e.id, date, date)
    const r = await apiRequest(page, { method: 'POST', path: '/api/workforce/planning/shifts', body: { employee_id: e.id, date, start_at: `${date} ${start}`, end_at: `${date} ${end}`, timezone: 'Europe/Paris' } })
    if (r.status === 201) {
      await apiRequest(page, { method: 'DELETE', path: `/api/workforce/planning/shifts/${r.json.id}` })

      return { id: e.id, name: `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim() }
    }
  }

  return null
}

// Pick an option in a Vuetify VSelect identified by its label.
export async function selectVuetifyOption(page, selectLabel, optionIndex = 0) {
  await page.getByLabel(selectLabel, { exact: false }).first().click()
  const opts = page.locator('.v-overlay-container .v-list-item, .v-menu .v-list-item')
  await opts.first().waitFor({ state: 'visible', timeout: 5000 })
  await opts.nth(optionIndex).click()
}
