// @ts-check
// ADR-776 (Company Experience 2.0 / UX1) — accueil : priorités, actions, vue d'ensemble.
//  1. Profils réels (lecture seule) : chaque priorité et chaque action servie s'ouvre sans refus.
//  2. Parcours exécutés depuis l'accueil (propriétaire) avec ÉCRITURE, sous registre d'exécution :
//     ajouter une personne, accéder à une demande, la valider, déposer, planning. Mesures relevées.
//  3. Entreprise sans Workforce (changement d'entreprise) : aucune priorité ni appel Workforce.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { apiRequest, clickAndExpectApi, loginCompany } from './helpers.js'
import { createRun } from './run-ledger.js'

const OUT = 'docs/bmad/ux1-776'
const PROFILES = [
  { tag: 'owner', email: 'owner@leezr.test' },
  { tag: 'dispatcher', email: 'admin@leezr.test' },
  { tag: 'rh', email: 'rh-nopay@leezr.test' },
  { tag: 'manager', email: 'manager@leezr.test' },
  { tag: 'paie', email: 'pay@leezr.test' },
  { tag: 'consultation', email: 'consultation@leezr.test' },
  { tag: 'driver', email: 'bob@leezr.test' },
]

const watch = page => {
  const bad = []
  const calls = []
  let at = ''
  page.on('response', r => {
    const url = r.url().replace(/^https?:\/\/[^/]+/, '')
    if (!url.startsWith('/api/')) return
    calls.push(url.split('?')[0])
    if (r.status() >= 400) bad.push({ at, status: r.status(), url: url.slice(0, 110) })
  })
  page.on('pageerror', e => bad.push({ at, js: String(e.message).slice(0, 160) }))
  return { bad, calls, mark: v => { at = v } }
}
const home = async page => {
  await page.evaluate(async () => { const r = document.querySelector('#app').__vue_app__.config.globalProperties.$router; await r.push('/dashboard').catch(() => {}) })
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
  await page.waitForTimeout(900)
}
// Compte les clics réels et les pages traversées d'un parcours.
const meter = async page => {
  await page.evaluate(() => {
    window.__ux = { clicks: 0, pages: [location.pathname] }
    if (!window.__uxBound) {
      window.__uxBound = true
      document.addEventListener('click', () => { window.__ux.clicks++ }, true)
      document.querySelector('#app').__vue_app__.config.globalProperties.$router.afterEach(to => { if (window.__ux.pages.at(-1) !== to.path) window.__ux.pages.push(to.path) })
    }
  })
  return async () => page.evaluate(() => ({ clicks: window.__ux.clicks, pages: window.__ux.pages.length - 1, path: window.__ux.pages }))
}

for (const p of PROFILES) {
  test(`UX1 accueil — ${p.tag}`, async ({ page }) => {
    test.setTimeout(5 * 60 * 1000)
    const net = watch(page)
    await loginCompany(page, p.email)
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
    await page.waitForTimeout(2500)
    const landing = new URL(page.url()).pathname
    const result = { profile: p, landing, priorities: [], actions: [], opened: [] }

    if (landing === '/dashboard') {
      const served = (await apiRequest(page, { path: '/api/dashboard/priorities' })).json
      const nav = (await apiRequest(page, { path: '/api/nav' })).json
      result.priorities = served.priorities.map(x => `${x.key}=${x.count}`)
      result.actions = nav.actions.filter(a => a.home && a.to).map(a => a.key)
      expect(served.unavailable).toEqual([])
      await expect(page.locator('[data-test="home-priorities"]')).toBeVisible()
      // Ce qui est affiché = ce qui est servi ; rien d'autre.
      await expect(page.locator('[data-test^="home-priority-"]')).toHaveCount(served.priorities.length)
      await expect(page.locator('[data-test^="home-action-"]')).toHaveCount(result.actions.length)
      if (!served.priorities.length) await expect(page.locator('[data-test="home-priorities-clear"]')).toBeVisible()
      result.metrics = await page.evaluate(() => ({ height: document.documentElement.scrollHeight, cards: document.querySelectorAll('.layout-page-content .v-card').length, customize: !!document.querySelector('[data-test="home-customize"]'), addWidgetVisible: !!document.querySelector('[data-test="home-add-widget"]') }))
      expect(result.metrics.addWidgetVisible, 'catalogue derrière Personnaliser').toBe(false)
      await page.screenshot({ path: `${OUT}/after-${p.tag}-desktop.jpg`, type: 'jpeg', quality: 55 })

      // Chaque priorité ouvre son traitement ; chaque action s'ouvre. Jamais de 403.
      for (const item of served.priorities) {
        net.mark(`priorité ${item.key}`)
        await home(page)
        await page.locator(`[data-test="home-priority-${item.key}"]`).click()
        await page.waitForURL(u => u.pathname !== '/dashboard', { timeout: 10000 }).catch(() => {})
        await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
        await page.waitForTimeout(600)
        const path = new URL(page.url()).pathname + new URL(page.url()).search
        expect(path, `${item.key} → pas de 403`).not.toContain('/403')
        expect(path, `${item.key} → ouvre un traitement`).not.toBe('/dashboard')
        result.opened.push({ key: item.key, path })
      }
      for (const key of result.actions) {
        net.mark(`action ${key}`)
        await home(page)
        await page.locator(`[data-test="home-action-${key}"]`).click()
        await page.waitForTimeout(1200)
        const path = new URL(page.url()).pathname + new URL(page.url()).search
        expect(path, `${key} → pas de 403`).not.toContain('/403')
        result.opened.push({ key, path })
        await page.keyboard.press('Escape')
      }
    }
    result.network = net.bad
    fs.writeFileSync(`${OUT}/result-${p.tag}.json`, JSON.stringify(result, null, 1))
    expect(net.bad, 'aucun refus réseau ni erreur JS').toEqual([])
  })
}

test('UX1 parcours exécutés depuis l\'accueil (propriétaire, registre d\'exécution)', async ({ page }) => {
  test.setTimeout(6 * 60 * 1000)
  const run = createRun('ux1')
  page.setDefaultTimeout(25000)
  const net = watch(page)
  const measures = {}
  try {
    await loginCompany(page, 'owner@leezr.test')
    run.useCompany(await page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || '')))
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-test="home-priorities"]', { timeout: 20000 })
    await page.waitForTimeout(2000)

    // 1. Consulter les priorités : visibles à l'ouverture, sans clic.
    measures.priorites = { clicks: 0, pages: 0, visibles: await page.locator('[data-test^="home-priority-"]').count() }

    // 2. Ajouter une personne depuis l'accueil.
    let read = await meter(page)
    net.mark('ajouter une personne')
    await page.locator('[data-test="home-action-team.add-person"]').click()
    // ADR-777 (UX2) : tiroir unique « Ajouter une personne » (l'action d'accueil prérépond « oui »).
    await page.locator('[data-test="person-add-first-name"] input').fill('UX1')
    await page.locator('[data-test="person-add-last-name"] input').fill(run.name('Accueil'))
    await page.locator('[data-test="person-add-email"] input').fill(run.email('ux1'))
    await page.locator('[data-test="person-add-staff"] input').uncheck()
    await page.locator('[data-test="person-add-role"]').click()
    await page.getByRole('option', { name: 'Driver' }).first().click()
    const created = await clickAndExpectApi(page, { urlIncludes: '/api/company/members', method: 'POST' },
      () => page.locator('[data-test="person-add-submit"]').click())
    const member = (await created.json()).member
    run.track('membership', member.id)
    run.track('user', member.user.id)
    measures.ajouter_personne = await read()

    // Préparation (API) : une pièce demandée à cette personne et déposée pour elle → « à valider ».
    const req = await apiRequest(page, { method: 'POST', path: '/api/company/document-requests', body: { user_id: member.user.id, document_type_code: 'id_card' } })
    expect(req.status, 'demande créée').toBeLessThan(300)
    const up = await page.evaluate(async id => {
      const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
      const fd = new FormData()
      fd.append('file', new File([new Blob(['%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF'])], 'piece-ux1.pdf', { type: 'application/pdf' }))
      const r = await fetch(`/api/company/members/${id}/documents/id_card`, { method: 'POST', body: fd, credentials: 'include', headers: { 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''), 'X-Company-Id': decodeURIComponent(get('currentCompanyId') || ''), Accept: 'application/json' } })
      return r.status
    }, member.id)
    expect(up, 'pièce déposée pour la personne').toBeLessThan(300)

    // 3. Accéder à la demande à valider : un clic depuis l'accueil, file déjà filtrée.
    await home(page)
    read = await meter(page)
    net.mark('accès demande')
    await page.locator('[data-test="home-priority-documents_to_review"]').click()
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
    const row = page.locator('tr', { hasText: run.email('ux1') }).first()
    await expect(row, 'la demande de la personne est dans la file ouverte').toBeVisible({ timeout: 15000 })
    measures.acces_demande = await read()
    expect(new URL(page.url()).search).toContain('status=submitted')
    await page.screenshot({ path: `${OUT}/journey-to-review.jpg`, type: 'jpeg', quality: 55 })

    // 4. Valider (suite du même parcours).
    net.mark('validation')
    const reviewed = page.waitForResponse(r => /\/documents\/id_card\/review$/.test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 })
    await row.locator('button[title="Approuver"], button:has(.tabler-check)').first().click()
    await page.locator('.v-overlay--active button', { hasText: /^\s*Confirmer\s*$/ }).first().click()
    expect((await reviewed).status(), 'validation acceptée').toBe(200)
    measures.validation = await read()

    // 5. Déposer un document : jusqu'au sélecteur de fichier du coffre (aucun fichier envoyé).
    await home(page)
    read = await meter(page)
    net.mark('dépôt')
    await page.locator('[data-test="home-action-documents.deposit"]').click()
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
    await expect(page.locator('input[type="file"]').first()).toBeAttached({ timeout: 15000 })
    measures.depot_document = await read()

    // 6. Accéder au planning.
    await home(page)
    read = await meter(page)
    net.mark('planning')
    await page.locator('[data-test="home-action-time.plan"]').click()
    await page.waitForURL(u => u.pathname !== '/dashboard', { timeout: 15000 }).catch(() => {})
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
    measures.planning = await read()
    expect(new URL(page.url()).pathname).toBe('/company/workforce/planning')
  }
  finally {
    measures.teardown = run.teardown()
    measures.network = net.bad
    fs.writeFileSync(`${OUT}/journeys-measures.json`, JSON.stringify(measures, null, 1))
  }
  expect(net.bad, 'aucun refus réseau ni erreur JS sur les parcours').toEqual([])
})

test('UX1 entreprise sans Workforce : ni priorité ni appel Workforce', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginCompany(page, 'owner@leezr.test')
  const list = (await apiRequest(page, { path: '/api/my-companies' })).json
  const companies = list.companies || list.data || list
  const other = companies.find(c => /Trial Logistics/.test(c.name))
  const origin = companies.find(c => !/Trial Logistics|M6E LOAD/.test(c.name))
  test.skip(!other, 'pas d\'entreprise sans Workforce pour ce compte')
  const sw = id => page.evaluate(i => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').switchCompany(i), id)
  await sw(other.id)
  const net = watch(page)
  try {
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-test="home-priorities"]', { timeout: 20000 })
    await page.waitForTimeout(2500)
    const served = (await apiRequest(page, { path: '/api/dashboard/priorities' })).json
    const keys = served.priorities.map(x => x.key)
    expect(keys.filter(k => /leave|timesheet|time_|payroll|onboarding|signature/.test(k))).toEqual([])
    expect(net.calls.filter(u => u.startsWith('/api/workforce')), 'aucun appel Workforce').toEqual([])
    await expect(page.locator('[data-test="home-action-time.plan"]')).toHaveCount(0)
    await page.screenshot({ path: `${OUT}/after-owner-no-workforce.jpg`, type: 'jpeg', quality: 55 })
    fs.writeFileSync(`${OUT}/result-no-workforce.json`, JSON.stringify({ company: other.name, priorities: keys, network: net.bad }, null, 1))
    expect(net.bad).toEqual([])
  }
  finally {
    await sw(origin.id)
    await page.reload({ waitUntil: 'domcontentloaded' })
  }
})
