// @ts-check
// ADR-777 (Company Experience 2.0 / UX2) — Équipe : un seul parcours d'ajout, fiche à cinq rubriques,
// actions libellées. Parcours EXÉCUTÉS avec écritures réelles, sous registre d'exécution ; profils
// réels en lecture seule ; mobile.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'
import { createRun } from './run-ledger.js'

const OUT = 'docs/bmad/ux2-777'
const GROUPS = ['overview', 'profile', 'documents', 'time', 'access']

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
  await page.waitForTimeout(700)
}
const meter = async page => {
  await page.evaluate(() => {
    window.__ux = { clicks: 0, pages: [location.pathname], t: performance.now() }
    if (!window.__uxBound) {
      window.__uxBound = true
      document.addEventListener('click', () => { window.__ux.clicks++ }, true)
      document.querySelector('#app').__vue_app__.config.globalProperties.$router.afterEach(to => { if (window.__ux.pages.at(-1) !== to.path) window.__ux.pages.push(to.path) })
    }
  })
  return async (fields = 0) => page.evaluate(f => ({ clicks: window.__ux.clicks, fields: f, pages: window.__ux.pages.length - 1, seconds: Math.round((performance.now() - window.__ux.t) / 100) / 10 }), fields)
}
const fill = (page, test, value) => page.locator(`[data-test="${test}"] input`).first().fill(value)
const tab = (page, key) => page.locator(`[data-test="asn-tab-${key}"], [data-test="asn-mtab-${key}"]`).first()

test('UX2 parcours exécutés (propriétaire, registre d\'exécution)', async ({ page }) => {
  test.setTimeout(8 * 60 * 1000)
  const run = createRun('ux2')
  page.setDefaultTimeout(25000)
  const net = watch(page)
  const m = {}
  try {
    await loginCompany(page, 'owner@leezr.test')
    run.useCompany(await page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || '')))
    await push(page, '/company/people')
    await expect(page.locator('[data-test="people-add"]')).toBeVisible({ timeout: 20000 })
    await expect(page.locator('[data-test="people-new"]'), 'plus de choix technique').toHaveCount(0)

    // ── 1. Ajouter une personne SANS accès ─────────────────────────────────────────
    let read = await meter(page)
    net.mark('ajout sans accès')
    await page.locator('[data-test="people-add"]').click()
    await fill(page, 'person-add-first-name', 'Lina')
    await fill(page, 'person-add-last-name', run.name('Sansacces'))
    await page.locator('[data-test="person-add-access-no"] input').check()
    const [emp] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/employees$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
      page.locator('[data-test="person-add-submit"]').click(),
    ])
    expect(emp.status()).toBe(201)
    const employeeId = (await emp.json()).id ?? (await emp.json()).data?.id
    run.track('employee', employeeId)
    await page.waitForURL(u => u.pathname === `/company/people/employee-${employeeId}`, { timeout: 15000 })
    await expect(page.locator('[data-test="person-header"]')).toContainText('Lina', { timeout: 15000 })
    m.ajout_sans_acces = await read(2)
    const file = (await apiRequest(page, { path: `/api/company/people/employee-${employeeId}` })).json
    expect(file.user_id, 'aucun compte créé').toBeNull()
    await page.screenshot({ path: `${OUT}/file-accountless.jpg`, type: 'jpeg', quality: 55 })

    // Rubriques : cinq au plus, aucune vide ; fiche sans compte pleinement exploitable.
    const shown = await page.locator('[data-test^="asn-tab-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('asn-tab-', '')))
    expect(shown.length).toBeLessThanOrEqual(5)
    expect(shown.every(k => GROUPS.includes(k))).toBe(true)
    m.rubriques_fiche_sans_compte = shown

    // ── 2. Trouver une personne ────────────────────────────────────────────────────
    await push(page, '/company/people')
    read = await meter(page)
    net.mark('trouver')
    await page.locator('input[placeholder*="Rechercher"]').first().fill(run.name('Sansacces'))
    const row = page.locator('tr', { hasText: run.name('Sansacces') }).first()
    await expect(row).toBeVisible({ timeout: 15000 })
    await row.click()
    await page.waitForURL(u => u.pathname === `/company/people/employee-${employeeId}`, { timeout: 15000 })
    m.trouver = await read(1)

    // ── 3. Modifier ses informations ───────────────────────────────────────────────
    read = await meter(page)
    net.mark('modifier')
    await page.locator('[data-test="person-edit"]').click()
    await page.waitForTimeout(900)
    await page.getByLabel('Matricule').fill(`UX2-${run.runId.slice(-5)}`)
    await Promise.all([
      page.waitForResponse(r => r.url().includes(`/workforce/employees/${employeeId}`) && r.request().method() === 'PUT', { timeout: 20000 }),
      page.getByRole('button', { name: /enregistrer|sauvegarder/i }).first().click(),
    ])
    m.modifier = await read(1)

    // ── 4. Générer un contrat (contrat préparé par l'API, génération par l'interface) ──
    const tomorrow = new Date(Date.now() + 864e5).toISOString().slice(0, 10)
    const contract = await apiRequest(page, { method: 'POST', path: `/api/workforce/employees/${employeeId}/contracts`, body: { contract_type: 'cdi', work_model_key: 'horaire', start_date: tomorrow, weekly_hours: 35 } })
    expect(contract.status, 'contrat préparé').toBe(201)
    await push(page, `/company/people/employee-${employeeId}`)
    await page.reload({ waitUntil: 'domcontentloaded' })
    await expect(page.locator('[data-test="person-actions-menu"]')).toBeVisible({ timeout: 20000 })
    read = await meter(page)
    net.mark('générer un contrat')
    await page.locator('[data-test="person-actions-menu"]').click()
    await page.screenshot({ path: `${OUT}/file-actions-menu.jpg`, type: 'jpeg', quality: 55 })
    await page.locator('[data-test="person-action-generate-contract"]').click()
    const dialog = page.locator('.v-overlay--active .v-card').last()
    await expect(dialog).toContainText('Générer un contrat')
    const generate = dialog.getByRole('button', { name: /^Générer$/ })
    await expect(dialog.locator('.v-select, .v-autocomplete').first()).toBeVisible({ timeout: 20000 }) // modèles chargés
    await page.waitForTimeout(600)
    if (await generate.isDisabled()) {
      await dialog.locator('.v-select, .v-autocomplete').first().click()
      await page.locator('.v-overlay--active .v-list-item').first().click()
    }
    const [gen] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/.*generate/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }),
      generate.click(),
    ])
    expect(gen.status(), 'contrat généré').toBeLessThan(300)
    m.generer_contrat = await read(0)

    // Automatisation existante : le contrat généré est classé dans le dossier, sans geste manuel.
    await page.keyboard.press('Escape')
    await tab(page, 'documents').click()
    await expect(page.locator('[data-test="employee-generated-documents"] tbody tr').first(), 'contrat classé automatiquement').toBeVisible({ timeout: 20000 })

    // ── 5. Déposer un document ─────────────────────────────────────────────────────
    await push(page, `/company/people/employee-${employeeId}`)
    read = await meter(page)
    net.mark('déposer un document')
    await page.locator('[data-test="person-actions-menu"]').click()
    await page.locator('[data-test="person-action-add-document"]').click()
    const section = page.locator('[data-test="person-section-documents"]')
    await expect(section).toBeVisible({ timeout: 15000 })
    await section.locator('input[type="file"]').first().setInputFiles({ name: 'piece-ux2.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF') })
    const [up] = await Promise.all([
      page.waitForResponse(r => new RegExp(`/api/company/people/employee-${employeeId}/documents/`).test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
      page.locator('.v-overlay--active button').filter({ hasText: /téléverser|confirmer|envoyer|valider|déposer/i }).last().click(),
    ])
    expect(up.status(), 'document déposé').toBeLessThan(300)
    m.deposer_document = await read(1)

    // ── 6. Ajouter une personne AVEC accès (fiche + invitation, une seule saisie) ───
    await push(page, '/company/people')
    read = await meter(page)
    net.mark('ajout avec accès')
    await page.locator('[data-test="people-add"]').click()
    await fill(page, 'person-add-first-name', 'Marc')
    await fill(page, 'person-add-last-name', run.name('Avecacces'))
    await page.locator('[data-test="person-add-access-yes"] input').check()
    await fill(page, 'person-add-email', run.email('ux2'))
    await page.locator('[data-test="person-add-role"]').click()
    await page.getByRole('option', { name: 'Driver' }).first().click()
    const [emp2, grant] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/employees$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
      page.waitForResponse(r => /\/api\/workforce\/employees\/\d+\/access$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
      page.locator('[data-test="person-add-submit"]').click(),
    ])
    const employee2 = (await emp2.json()).id ?? (await emp2.json()).data?.id
    run.track('employee', employee2)
    expect(grant.status(), 'accès préparé').toBeLessThan(300)
    await page.waitForURL(u => u.pathname === `/company/people/employee-${employee2}`, { timeout: 15000 })
    await expect(page.locator('[data-test="person-header"]')).toContainText('Marc', { timeout: 15000 })
    m.ajout_avec_acces = await read(4)
    const file2 = (await apiRequest(page, { path: `/api/company/people/employee-${employee2}` })).json
    run.track('membership', file2.membership_id)
    run.track('user', file2.user_id)
    expect(file2.user_id, 'UNE personne : fiche et compte liés').not.toBeNull()
    // Une seule ligne dans la liste pour cette personne.
    const list = (await apiRequest(page, { path: `/api/company/people?search=${encodeURIComponent(run.name('Avecacces'))}` })).json
    expect(list.data.length, 'jamais deux lignes pour une personne').toBe(1)

    // ── 7. Suspendre un accès ─────────────────────────────────────────────────────
    read = await meter(page)
    net.mark('suspendre')
    await page.locator('[data-test="person-actions-menu"]').click()
    await page.locator('[data-test="person-action-suspend"]').click()
    await page.locator('[data-test="person-suspend-reason"] input').fill('Recette UX2')
    await Promise.all([
      page.waitForResponse(r => /suspend/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
      page.locator('[data-test="person-suspend-confirm"]').click(),
    ])
    await expect(page.locator('[data-test="person-account-state"]')).toContainText('suspendu', { timeout: 15000 })
    m.suspendre_acces = await read(1)

    // ── 8. Inviter un compte EXISTANT (acceptation explicite, pas d'accès activé) ──
    await push(page, '/company/people')
    read = await meter(page)
    net.mark('compte existant')
    await page.locator('[data-test="people-add"]').click()
    await fill(page, 'person-add-first-name', 'Trial')
    await fill(page, 'person-add-last-name', 'Existant')
    await page.locator('[data-test="person-add-access-yes"] input').check()
    await fill(page, 'person-add-email', 'trial@leezr.test')
    await page.locator('[data-test="person-add-role"]').click()
    await page.getByRole('option', { name: 'Driver' }).first().click()
    await page.locator('[data-test="person-add-staff"] input').uncheck()
    const [inv] = await Promise.all([
      page.waitForResponse(r => /\/api\/company\/members$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
      page.locator('[data-test="person-add-submit"]').click(),
    ])
    const invited = await inv.json()
    expect(inv.status()).toBeLessThan(300)
    expect(invited.pending_acceptance, 'compte existant → acceptation explicite').toBe(true)
    m.inviter_compte_existant = await read(4)
    await page.waitForTimeout(1500)
    await expect(page.locator('[data-test="person-account-state"]')).toContainText(/attente/i, { timeout: 15000 })
    // Le compte n'appartient pas à cette exécution : on retire NOTRE invitation par l'opération de
    // l'application (le démontage, lui, ne touche pas une adhésion dont le compte n'est pas le sien).
    const removed = await apiRequest(page, { method: 'DELETE', path: `/api/company/members/${invited.member.id}` })
    expect(removed.status, 'invitation retirée').toBeLessThan(300)

    // ── 9. Trouver un bulletin autorisé (lecture) ──────────────────────────────────
    const people = (await apiRequest(page, { path: '/api/company/people?per_page=100' })).json.data
    const me = people.find(r => r.email === 'owner@leezr.test')
    await push(page, `/company/people/${me.person_ref}`)
    read = await meter(page)
    net.mark('bulletin')
    await tab(page, 'documents').click()
    await expect(page.locator('[data-test="person-section-payslips"]')).toBeVisible({ timeout: 15000 })
    m.trouver_bulletin = await read(0)
  }
  finally {
    m.teardown = run.teardown()
    m.network = net.bad
    fs.writeFileSync(`${OUT}/journeys-measures.json`, JSON.stringify(m, null, 1))
  }
  expect(net.bad, 'aucun refus réseau ni erreur JS').toEqual([])
})

const PROFILES = [
  { tag: 'rh', email: 'rh-nopay@leezr.test', note: 'RH sans droits Members ni gestion des fiches', add: null },
  { tag: 'dispatcher', email: 'admin@leezr.test', note: 'Members sans droits Workforce', add: 'access' },
  { tag: 'manager', email: 'manager@leezr.test', note: 'manager limité à son équipe', add: null },
  { tag: 'paie', email: 'pay@leezr.test', note: 'gestionnaire paie', add: null },
  { tag: 'consultation', email: 'consultation@leezr.test', note: 'lecture seule', add: null },
]
for (const p of PROFILES) {
  test(`UX2 profil — ${p.tag} (${p.note})`, async ({ page }) => {
    test.setTimeout(4 * 60 * 1000)
    const net = watch(page)
    await loginCompany(page, p.email)
    const nav = (await apiRequest(page, { path: '/api/nav' })).json
    const hasTeam = nav.groups.some(g => g.key === 'team')
    const result = { profile: p, hasTeam, files: [] }
    if (hasTeam) {
      await push(page, '/company/people')
      await page.waitForTimeout(1500)
      const addBtn = page.locator('[data-test="people-add"]')
      expect(await addBtn.count(), 'ajout proposé seulement si possible').toBe(p.add ? 1 : 0)
      if (p.add) {
        await addBtn.click()
        // La question n'est posée que si les deux réponses sont possibles ; sinon rien d'impossible n'est demandé.
        await expect(page.locator('[data-test="person-add-access"]')).toHaveCount(0)
        await expect(page.locator('[data-test="person-add-email"]')).toHaveCount(p.add === 'access' ? 1 : 0)
        await expect(page.locator('[data-test="person-add-hire-date"]')).toHaveCount(p.add === 'record' ? 1 : 0)
        await page.screenshot({ path: `${OUT}/add-${p.tag}.jpg`, type: 'jpeg', quality: 55 })
        await page.keyboard.press('Escape')
      }
      await page.screenshot({ path: `${OUT}/list-${p.tag}.jpg`, type: 'jpeg', quality: 55 })
      const rows = (await apiRequest(page, { path: '/api/company/people?per_page=100' })).json.data
      result.rows = rows.length
      // Personne en double ? Jamais : une référence par personne.
      expect(new Set(rows.map(r => r.person_ref)).size).toBe(rows.length)
      for (const r of [rows.find(x => x.employee_id && x.user_id), rows.find(x => x.employee_id && !x.user_id), rows.find(x => !x.employee_id && x.user_id)].filter(Boolean)) {
        net.mark(`fiche ${r.person_ref}`)
        await push(page, `/company/people/${r.person_ref}`)
        await expect(page.locator('[data-test="person-header"]')).toBeVisible({ timeout: 15000 })
        const keys = await page.locator('[data-test^="asn-tab-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('asn-tab-', '')))
        expect(keys.length).toBeLessThanOrEqual(5)
        for (const k of keys) {
          await tab(page, k).click()
          await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {})
          await page.waitForTimeout(500)
        }
        result.files.push({ ref: r.person_ref, groups: keys, actions: await page.locator('[data-test="person-actions-menu"]').count() })
      }
      await page.screenshot({ path: `${OUT}/file-${p.tag}.jpg`, type: 'jpeg', quality: 55 })
    }
    result.network = net.bad
    fs.writeFileSync(`${OUT}/result-${p.tag}.json`, JSON.stringify(result, null, 1))
    expect(net.bad, 'aucun refus réseau ni erreur JS').toEqual([])
  })
}

test('UX2 entreprise sans Workforce : ajout sans fiche salarié, fiche légère', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginCompany(page, 'owner@leezr.test')
  const list = (await apiRequest(page, { path: '/api/my-companies' })).json
  const companies = list.companies || list.data || list
  const other = companies.find(c => /Trial Logistics/.test(c.name))
  const origin = companies.find(c => !/Trial Logistics|M6E LOAD/.test(c.name))
  test.skip(!other, 'pas d\'entreprise sans Workforce pour ce compte')
  const sw = async id => { await page.evaluate(i => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').switchCompany(i), id); await page.reload({ waitUntil: 'domcontentloaded' }) }
  await sw(other.id)
  const net = watch(page)
  try {
    await page.goto('/company/people', { waitUntil: 'domcontentloaded' })
    await expect(page.locator('[data-test="people-add"]')).toBeVisible({ timeout: 20000 })
    await page.locator('[data-test="people-add"]').click()
    await expect(page.locator('[data-test="person-add-access"]'), 'pas de question : seule l\'invitation existe').toHaveCount(0)
    await expect(page.locator('[data-test="person-add-hire-date"]'), 'aucune information RH demandée').toHaveCount(0)
    await expect(page.locator('[data-test="person-add-staff"]')).toHaveCount(0)
    await page.screenshot({ path: `${OUT}/add-no-workforce.jpg`, type: 'jpeg', quality: 55 })
    await page.keyboard.press('Escape')
    const rows = (await apiRequest(page, { path: '/api/company/people?per_page=50' })).json.data
    await push(page, `/company/people/${rows[0].person_ref}`)
    await expect(page.locator('[data-test="person-header"]')).toBeVisible({ timeout: 15000 })
    const keys = await page.locator('[data-test^="asn-tab-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('asn-tab-', '')))
    expect(keys, 'fiche légère : ni temps ni contrat').not.toContain('time')
    await page.screenshot({ path: `${OUT}/file-no-workforce.jpg`, type: 'jpeg', quality: 55 })
    fs.writeFileSync(`${OUT}/result-no-workforce.json`, JSON.stringify({ company: other.name, groups: keys, network: net.bad }, null, 1))
    expect(net.bad).toEqual([])
  }
  finally { await sw(origin.id) }
})

test.describe('mobile', () => {
  test.use({ viewport: { width: 390, height: 844 } })
  test('UX2 mobile : fiche et ajout sans débordement, actions accessibles', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(25000)
  const net = watch(page)
  await loginCompany(page, 'owner@leezr.test')
  const rows = (await apiRequest(page, { path: '/api/company/people?per_page=100' })).json.data
  const linked = rows.find(r => r.employee_id && r.user_id && r.email !== 'owner@leezr.test')
  await page.goto(`/company/people/${linked.person_ref}`, { waitUntil: 'domcontentloaded' })
  await expect(page.locator('[data-test="person-header"]')).toBeVisible({ timeout: 20000 })
  await page.waitForTimeout(1500)
  const overflow = () => page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
  expect(await overflow(), 'fiche sans débordement').toBe(false)
  for (const sel of ['[data-test="person-edit"]', '[data-test="person-actions-menu"]']) {
    const box = await page.locator(sel).boundingBox()
    expect(box && box.x >= 0 && box.x + box.width <= 390, `${sel} dans l'écran`).toBe(true)
  }
  await expect(page.locator('[data-test="person-group-label"]'), 'rubrique nommée sur mobile').toBeVisible()
  await page.screenshot({ path: `${OUT}/mobile-file.jpg`, type: 'jpeg', quality: 55 })
  await page.locator('[data-test="person-actions-menu"]').click()
  await page.waitForTimeout(500)
  await page.screenshot({ path: `${OUT}/mobile-actions.jpg`, type: 'jpeg', quality: 55 })
  await page.keyboard.press('Escape')
  for (const k of await page.locator('[data-test^="asn-mtab-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('asn-mtab-', '')))) {
    await page.locator(`[data-test="asn-mtab-${k}"]`).click()
    await page.waitForTimeout(1200)
    expect(await overflow(), `rubrique ${k} sans débordement`).toBe(false)
  }
  await page.goto('/company/people', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-test="people-add"]').click()
  await page.waitForTimeout(800)
  expect(await overflow(), 'tiroir d\'ajout sans débordement').toBe(false)
  await page.screenshot({ path: `${OUT}/mobile-add.jpg`, type: 'jpeg', quality: 55 })
  expect(net.bad).toEqual([])
  })
})
