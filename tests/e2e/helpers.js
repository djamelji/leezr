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

// Pick an option in a Vuetify VSelect identified by its label.
export async function selectVuetifyOption(page, selectLabel, optionIndex = 0) {
  await page.getByLabel(selectLabel, { exact: false }).first().click()
  const opts = page.locator('.v-overlay-container .v-list-item, .v-menu .v-list-item')
  await opts.first().waitFor({ state: 'visible', timeout: 5000 })
  await opts.nth(optionIndex).click()
}
