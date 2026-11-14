// @ts-check
// ADR-781 (Company Experience 2.0 / UX5) — Réglages : hub unique, rubriques, liens contextuels, retour.
// Écritures UNIQUEMENT dans l'entreprise de recette « Beta Services » (ownerb@leezr.test) :
//  - le téléphone modifié est remis à sa valeur d'origine (finally) ;
//  - le site créé par l'exécution est supprimé par son identifiant exact ;
//  - le premier jour de semaine est remis à sa valeur d'origine.
// L'entreprise de démonstration (owner@leezr.test) n'est lue qu'en lecture. Aucun salarié de démo n'est utilisé.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

const OUT = 'docs/bmad/ux5-781'
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
const meter = async page => {
  await page.evaluate(() => {
    window.__ux = { clicks: 0, pages: [location.pathname] }
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
const shot = (page, name) => page.screenshot({ path: `${OUT}/${name}.jpg`, type: 'jpeg', quality: 55 })
const openSettings = async page => {
  await page.locator('.layout-vertical-nav a[href="/company/settings"]').first().click()
  await page.waitForURL(/\/company\/settings$/)
  await page.waitForLoadState('networkidle').catch(() => {})
}
const home = async page => { await page.goto('/dashboard', { waitUntil: 'networkidle' }).catch(() => {}); await page.waitForTimeout(800) }

test('UX5 parcours propriétaire (lecture) : hub, rôle, absence, factures, intégration, retour', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  page.setDefaultTimeout(20000)
  const net = watch(page)
  const m = {}
  await loginCompany(page, 'owner@leezr.test')
  await home(page)

  // Menu : « Réglages » est UNE entrée (aucun sous-menu).
  const navItem = page.locator('.layout-vertical-nav a[href="/company/settings"]')
  await expect(navItem.first()).toBeVisible()
  m.menu_sub_entries = await page.locator('.layout-vertical-nav .nav-group', { hasText: 'Réglages' }).count()

  let read = await meter(page)
  net.mark('hub')
  await openSettings(page)
  await expect(page.locator('[data-test="settings-section-company"]')).toBeVisible()
  m.ouvrir_reglages = await read(0)
  m.sections = await page.locator('[data-test^="settings-section-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('settings-section-', '')))
  m.entries_first_level = await page.locator('section [data-test^="settings-entry-"]').count()
  m.advanced_folded = await page.locator('[data-test="settings-advanced"] [data-test^="settings-entry-"]').count() === 0
  await shot(page, 'j-desktop-01-hub')

  // 3. Trouver un rôle et ses permissions
  read = await meter(page)
  net.mark('rôles')
  await page.locator('[data-test="settings-entry-settings-roles"]').click()
  await page.waitForURL(/\/company\/people\?.*tab=roles/)
  await expect(page.getByRole('tab', { name: /Rôles/ }).first()).toHaveAttribute('aria-selected', 'true')
  await expect(page.locator('[data-test="settings-return"]')).toBeVisible()
  m.trouver_role = await read(0)
  await shot(page, 'j-desktop-02-roles')
  await page.locator('[data-test="settings-return"]').click()
  await page.waitForURL(/\/company\/settings$/)

  // 6. Retrouver un paramètre d'absence (recherche)
  read = await meter(page)
  net.mark('absence')
  await page.locator('[data-test="settings-search"] input').fill('absence')
  await page.locator('[data-test="settings-entry-settings-leave-types"]').click()
  await page.waitForURL(/\/company\/workforce\/leave\?.*tab=config/)
  await expect(page.getByRole('tab', { name: /Types de congés/ }).first()).toHaveAttribute('aria-selected', 'true')
  m.parametre_absence = await read(1)

  // 7. Abonnement et factures
  await openSettings(page)
  read = await meter(page)
  net.mark('factures')
  await page.locator('[data-test="settings-entry-billing"]').click()
  await page.waitForURL(/\/company\/billing\//)
  m.abonnement_factures = await read(0)
  await shot(page, 'j-desktop-03-billing')

  // 8. Intégration à compléter : l'état est visible sur le hub, sans ouvrir la page
  await openSettings(page)
  const sig = page.locator('[data-test="settings-status-settings-signature"]')
  if (await page.locator('[data-test="settings-entry-settings-signature"]').count()) {
    await expect(sig).toBeVisible()
    m.integration_etat = await sig.innerText()
  }
  const prof = page.locator('[data-test="settings-status-company-profile"]')
  if (await prof.count()) m.profil_etat = await prof.innerText()

  // 5. Configurer un type de document (ouverture de l'écran de réglage ; aucune écriture sur l'entreprise de démo)
  read = await meter(page)
  net.mark('types de document')
  await page.locator('[data-test="settings-entry-settings-documents"]').click()
  await page.waitForURL(/\/company\/documents\/settings/)
  m.type_document = await read(0)

  // Administration avancée : repliée, puis ouverte
  await openSettings(page)
  await page.locator('[data-test="settings-advanced-toggle"]').click()
  await expect(page.locator('[data-test="settings-advanced"] [data-test="settings-entry-modules"]')).toBeVisible()
  await shot(page, 'j-desktop-04-advanced')

  // 9. Revenir à l'opération d'origine : Planning → Préférences → Retour
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  read = await meter(page)
  net.mark('retour')
  await page.locator('[data-test="space-preferences"]').click()
  await page.waitForURL(/\/company\/workforce\/settings\//)
  await page.locator('[data-test="settings-return"]').click()
  await page.waitForURL(/\/company\/workforce\/planning$/)
  m.preferences_puis_retour = await read(0)

  fs.writeFileSync(`${OUT}/measures-owner.json`, JSON.stringify({ measures: m, errors: net.bad }, null, 2))
  expect(net.bad, 'aucune erreur réseau ni JS').toEqual([])
})

test('UX5 parcours avec écritures (entreprise de recette Beta Services)', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  page.setDefaultTimeout(20000)
  const net = watch(page)
  const m = {}
  const stamp = Date.now().toString(36)
  let originalPhone = null
  let createdLocation = null
  let originalWeekStart
  await loginCompany(page, 'ownerb@leezr.test')
  const cid = await page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || ''))
  const me = (await apiRequest(page, { path: '/api/company' })).json
  test.skip(!/Beta Services/.test(me?.base_fields?.name || me?.name || ''), `entreprise courante inattendue (${cid})`)
  try {
    await home(page)
    // 1. Modifier les coordonnées
    originalPhone = me.base_fields?.name ?? me.name // valeur d'origine (restaurée en fin d'exécution)
    let read = await meter(page)
    net.mark('coordonnées')
    await openSettings(page)
    await page.locator('[data-test="settings-entry-company-profile"]').click()
    await page.waitForURL(/\/company\/profile\//)
    m.profil_champs_visibles = await page.locator('main input:not([type=hidden]):visible, main textarea:visible').count()
    // Le champ disponible dépend du métier de l'entreprise : ici le nom (la recette le restaure).
    await expect(page.locator('[data-test="company-name"] input')).toHaveValue(originalPhone)
    await page.waitForTimeout(500)
    await page.locator('[data-test="company-name"] input').fill(`${originalPhone} ${stamp}`)
    const [put] = await Promise.all([
      page.waitForResponse(r => /\/api\/company$/.test(r.url()) && r.request().method() === 'PUT'),
      page.locator('[data-test="company-save"]').click(),
    ])
    expect(put.status(), `coordonnées enregistrées (${(await put.text()).slice(0, 160)})`).toBe(200)
    m.modifier_coordonnees = await read(1)
    await shot(page, 'j-desktop-05-profile')

    // 2. Configurer un site
    read = await meter(page)
    net.mark('site')
    await openSettings(page)
    await page.locator('[data-test="settings-entry-workforce-organization"]').click()
    await page.waitForURL(/\/company\/workforce\/organization\//)
    await page.getByRole('tab', { name: /Lieux/ }).first().click()
    await page.locator('[data-test="org-loc-create"]').click()
    await page.locator('[data-test="org-loc-name"] input').fill(`Site recette ${stamp}`)
    const [loc] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/(planning\/)?locations$/.test(r.url()) && r.request().method() === 'POST'),
      page.locator('[data-test="org-loc-submit"]').click(),
    ])
    expect(loc.status(), `site créé (${(await loc.text()).slice(0, 160)})`).toBeLessThan(300)
    const locJson = await loc.json()
    createdLocation = { id: locJson.id ?? locJson.data?.id, path: new URL(loc.url()).pathname }
    expect(locJson.timezone ?? locJson.data?.timezone, 'fuseau prérempli (entreprise)').toBeTruthy()
    m.configurer_site = await read(1)

    // 4. Modifier une préférence d'organisation (premier jour de la semaine)
    originalWeekStart = (await apiRequest(page, { path: '/api/workforce/config/week-start' })).json
    read = await meter(page)
    net.mark('préférence')
    await openSettings(page)
    await page.locator('[data-test="settings-entry-workforce-settings"]').click()
    await page.waitForURL(/\/company\/workforce\/settings\//)
    await page.locator('[data-test="week-start-select"]').click()
    await page.locator('.v-overlay--active .v-list-item', { hasText: /Dimanche/ }).first().click()
    const [ws] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/config\/week-start$/.test(r.url()) && r.request().method() === 'PUT'),
      page.locator('[data-test="week-start-select"]').locator('xpath=following::button[1]').click(),
    ])
    expect(ws.status(), 'préférence enregistrée').toBeLessThan(300)
    m.modifier_preference = await read(1)
  }
  finally {
    // Remise en état : seulement ce que cette exécution a modifié ou créé.
    if (originalPhone !== null) {
      const dyn = Object.fromEntries((me.dynamic_fields || []).map(f => [f.code, f.value]))
      m.restore_name = (await apiRequest(page, { method: 'PUT', path: '/api/company', body: { name: originalPhone, ...(Object.keys(dyn).length ? { dynamic_fields: dyn } : {}) } })).status
    }
    if (createdLocation?.id) m.delete_location = (await apiRequest(page, { method: 'DELETE', path: `${createdLocation.path.replace(/^\/api/, '/api')}/${createdLocation.id}` })).status
    if (originalWeekStart) m.restore_week_start = (await apiRequest(page, { method: 'PUT', path: '/api/workforce/config/week-start', body: { week_start_day: originalWeekStart.source === 'company' ? originalWeekStart.week_start_day : null } })).status
    fs.writeFileSync(`${OUT}/measures-writes.json`, JSON.stringify({ measures: m, errors: net.bad }, null, 2))
  }
  expect(net.bad.filter(b => b.js || b.status >= 500), 'aucune erreur').toEqual([])
})

test('UX5 mobile, tablette, sombre : hub utilisable', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const net = watch(page)
  await loginCompany(page, 'owner@leezr.test')
  for (const [label, w, h] of [['mobile', 390, 844], ['tablet', 820, 1180]]) {
    await page.setViewportSize({ width: w, height: h })
    await page.goto('/company/settings', { waitUntil: 'networkidle' })
    await page.waitForTimeout(800)
    expect(await page.evaluate(() => document.documentElement.scrollWidth), `${label} : pas de défilement horizontal`).toBeLessThanOrEqual(w)
    await shot(page, `j-${label}-hub`)
  }
  // 10. Paramètres sur mobile : chercher et ouvrir
  await page.setViewportSize({ width: 390, height: 844 })
  const read = await meter(page)
  await page.locator('[data-test="settings-search"] input').fill('factures')
  await page.locator('[data-test="settings-entry-billing"]').click()
  await page.waitForURL(/\/company\/billing\//)
  const mobile = await read(1)
  expect(mobile.hscroll).toBe(false)
  await shot(page, 'j-mobile-billing')
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.evaluate(() => { const s = document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('config'); if (s) s.theme = 'dark' })
  await page.goto('/company/settings', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await shot(page, 'j-dark-hub')
  await page.evaluate(() => { const s = document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('config'); if (s) s.theme = 'light' })
  fs.writeFileSync(`${OUT}/measures-mobile.json`, JSON.stringify({ mobile, errors: net.bad }, null, 2))
  expect(net.bad.filter(b => b.js || b.status >= 500)).toEqual([])
})

// ── Autorisations : chaque profil voit seulement les réglages qu'il peut ouvrir ─────────────────
const PROFILES = ['owner', 'admin', 'rh-nopay', 'manager', 'bob', 'pay', 'consultation', 'salarie']
test('UX5 profils : entrées du hub = pages réellement accessibles', async ({ page }) => {
  test.setTimeout(10 * 60 * 1000)
  const result = {}
  for (const who of PROFILES) {
    await page.context().clearCookies()
    const net = watch(page)
    await loginCompany(page, `${who}@leezr.test`)
    const nav = (await apiRequest(page, { path: '/api/nav' })).json
    const hub = (nav.groups || []).find(g => g.key === 'settings')?.items?.find(i => i.hub)
    const entries = hub?.children || []
    const visits = {}
    for (const e of entries) {
      const url = await page.evaluate(to => document.querySelector('#app').__vue_app__.config.globalProperties.$router.resolve(to).href, e.to)
      await page.goto(url, { waitUntil: 'networkidle' })
      await page.waitForTimeout(700)
      visits[e.key] = new URL(page.url()).pathname === url.split('?')[0]
    }
    await page.goto('/company/settings', { waitUntil: 'networkidle' })
    await page.waitForTimeout(800)
    const shown = await page.locator('[data-test^="settings-entry-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('settings-entry-', '')))
    // Pages sensibles hors droits : refusées (redirigées), jamais affichées.
    const sensitive = {}
    for (const [k, p] of [['billing', '/company/billing/overview'], ['roles', '/company/roles'], ['modules', '/company/modules']]) {
      if (entries.some(e => e.key === k || (k === 'roles' && e.key === 'settings-roles'))) continue
      await page.goto(p, { waitUntil: 'networkidle' })
      await page.waitForTimeout(600)
      sensitive[k] = new URL(page.url()).pathname
    }
    await page.screenshot({ path: `${OUT}/hub-${who}.jpg`, type: 'jpeg', quality: 45 })
    result[who] = { entries: entries.map(e => e.key), shown, visits, sensitive, errors: net.bad.filter(b => b.js || b.status >= 500 || b.status === 403) }
  }
  fs.writeFileSync(`${OUT}/result-profiles.json`, JSON.stringify(result, null, 1))
  for (const [who, r] of Object.entries(result)) {
    expect(r.errors, `${who} : ni erreur JS, ni 403, ni 5xx`).toEqual([])
    for (const [k, ok] of Object.entries(r.visits)) expect(ok, `${who} : « ${k} » proposé mais refusé`).toBe(true)
    for (const k of r.shown) expect(r.entries, `${who} : « ${k} » affiché mais non autorisé`).toContain(k)
    for (const [k, landed] of Object.entries(r.sensitive)) expect(landed, `${who} : ${k} refusé`).not.toMatch(new RegExp(k === 'billing' ? '/company/billing' : `/company/${k}`))
  }
})

test('UX5 changement d\'entreprise : le hub suit les modules de l\'entreprise', async ({ page }) => {
  const net = watch(page)
  await loginCompany(page, 'owner@leezr.test')
  const before = ((await apiRequest(page, { path: '/api/nav' })).json.groups.find(g => g.key === 'settings')?.items?.[0]?.children || []).map(e => e.key)
  const list = (await apiRequest(page, { path: '/api/my-companies' })).json
  const companies = list.companies || list.data || list
  const other = companies.find(c => /Trial Logistics/.test(c.name))
  test.skip(!other, 'pas d\'entreprise sans Workforce')
  await page.evaluate(id => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').switchCompany(id), other.id)
  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForSelector('.layout-vertical-nav .nav-link', { timeout: 20000 })
  const after = ((await apiRequest(page, { path: '/api/nav' })).json.groups.find(g => g.key === 'settings')?.items?.[0]?.children || []).map(e => e.key)
  await page.goto('/company/settings', { waitUntil: 'networkidle' })
  await page.waitForTimeout(900)
  await shot(page, 'hub-no-workforce')
  const shown = await page.locator('[data-test^="settings-entry-"]').count()
  const homeCo = companies.find(c => !/Trial Logistics|M6E LOAD|Beta/.test(c.name))
  await page.evaluate(id => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').switchCompany(id), homeCo.id)
  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)
  fs.writeFileSync(`${OUT}/result-company-switch.json`, JSON.stringify({ before, after, shownAfter: shown }, null, 1))
  expect(after.filter(k => /^workforce|leave|signature|templates/.test(k)), 'aucun réglage Workforce sans le module').toEqual([])
  expect(after.length).toBeLessThan(before.length)
  expect(net.bad.filter(b => b.js)).toEqual([])
})
