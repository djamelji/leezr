// @ts-check
// ADR-778 (Company Experience 2.0 / UX3) — Documents : quatre vues, « Nouveau », recherche serveur,
// actions directes depuis la fiche. Parcours EXÉCUTÉS avec écritures réelles sous registre d'exécution
// (le démontage supprime la fiche ET ses documents) ; profils en lecture ; mobile.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'
import { createRun } from './run-ledger.js'

const OUT = 'docs/bmad/ux3-778'
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')

const watch = page => {
  const bad = []
  let at = ''
  page.on('response', r => {
    const url = r.url().replace(/^https?:\/\/[^/]+/, '')
    // ADR-784 : un 429 sur /api/login est réessayé par loginCompany (respect de Retry-After) : ce
    // n'est pas une erreur du produit. Toute autre réponse >= 400 reste comptée. LOGIN_THROTTLE
    if (url.startsWith('/api/login') && r.status() === 429) return
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
    window.__ux = { clicks: 0, pages: [location.pathname] }
    if (!window.__uxBound) {
      window.__uxBound = true
      document.addEventListener('click', () => { window.__ux.clicks++ }, true)
      document.querySelector('#app').__vue_app__.config.globalProperties.$router.afterEach(to => { if (window.__ux.pages.at(-1) !== to.path) window.__ux.pages.push(to.path) })
    }
  })
  return async (fields = 0) => page.evaluate(f => ({ clicks: window.__ux.clicks, fields: f, pages: window.__ux.pages.length - 1 }), fields)
}
const pick = async (page, test, index = 0) => {
  await page.locator(`[data-test="${test}"]`).click()
  await page.locator('.v-overlay--active .v-list-item').nth(index).click()
}

test('UX3 parcours exécutés (propriétaire, registre d\'exécution)', async ({ page }) => {
  test.setTimeout(8 * 60 * 1000)
  page.setDefaultTimeout(25000)
  const run = createRun('ux3')
  const net = watch(page)
  const m = {}
  let companyDocCode = null
  try {
    await loginCompany(page, 'owner@leezr.test')
    run.useCompany(await page.evaluate(() => decodeURIComponent((document.cookie.match(/currentCompanyId=([^;]+)/) || [])[1] || '')))

    // Préparation (API) : une personne de cette exécution, avec un contrat en brouillon.
    const emp = await apiRequest(page, { method: 'POST', path: '/api/workforce/employees', body: { first_name: 'Dora', last_name: run.name('Docs'), hire_date: new Date().toISOString().slice(0, 10) } })
    const employeeId = emp.json.id ?? emp.json.data?.id
    run.track('employee', employeeId)
    const ref = `employee-${employeeId}`
    const tomorrow = new Date(Date.now() + 864e5).toISOString().slice(0, 10)
    expect((await apiRequest(page, { method: 'POST', path: `/api/workforce/employees/${employeeId}/contracts`, body: { contract_type: 'cdi', work_model_key: 'horaire', start_date: tomorrow, weekly_hours: 35 } })).status).toBe(201)

    // ── 1. Déposer un document de l'ENTREPRISE (sujet déjà connu, type + fichier) ──────────
    const vault = (await apiRequest(page, { path: '/api/company/documents' })).json.documents
    const free = vault.find(d => !d.upload)
    test.skip(!free, 'aucun type entreprise libre pour un dépôt de recette')
    await push(page, '/company/documents/company')
    await expect(page.locator('[data-test="documents-new"]')).toBeVisible()
    let read = await meter(page)
    net.mark('dépôt entreprise')
    await page.locator('[data-test="documents-new"]').click()
    await page.locator('[data-test="documents-new-deposit"]').click()
    await expect(page.locator('[data-test="document-deposit-subject"]'), 'sujet prérempli : entreprise').toContainText("L'entreprise")
    await page.locator('[data-test="document-deposit-type"]').click()
    await page.locator('.v-overlay--active .v-list-item').nth(vault.indexOf(free)).click()
    await page.locator('[data-test="document-deposit-file"]').setInputFiles({ name: 'entreprise-ux3.pdf', mimeType: 'application/pdf', buffer: PDF })
    const [co] = await Promise.all([
      page.waitForResponse(r => /\/api\/company\/documents\/[a-z_]+$/.test(r.url()) && r.request().method() === 'POST'),
      page.locator('[data-test="document-deposit-submit"]').click(),
    ])
    expect(co.status(), 'document entreprise déposé').toBeLessThan(300)
    companyDocCode = new URL(co.url()).pathname.split('/').pop()
    m.deposer_entreprise = await read(2)

    // ── 2. Déposer un document pour un COLLABORATEUR (personne préremplie) ────────────────
    await expect(page.locator('[data-test="document-deposit"]')).toHaveCount(0)
    await page.waitForTimeout(800)
    await push(page, `/company/people/${ref}`)
    await page.waitForURL(u => u.pathname === `/company/people/${ref}`)
    await expect(page.locator('[data-test="person-actions-menu"]')).toBeVisible()
    read = await meter(page)
    net.mark('dépôt collaborateur')
    await page.locator('[data-test="person-actions-menu"]').click()
    await page.locator('[data-test="person-action-add-document"]').click()
    await expect(page.locator('[data-test="document-deposit-subject"]'), 'personne préremplie').toContainText('Dora')
    await expect(page.locator('[data-test="document-deposit-person"]'), 'aucune sélection de personne').toHaveCount(0)
    await page.locator('[data-test="document-deposit-type"]').click()
    await page.locator('.v-overlay--active .v-list-item', { hasText: /Certificat m/i }).first().click()
    await page.locator('[data-test="document-deposit-file"]').setInputFiles({ name: 'certificat-ux3.pdf', mimeType: 'application/pdf', buffer: PDF })
    const [pe] = await Promise.all([
      page.waitForResponse(r => new RegExp(`/api/company/people/${ref}/documents/medical_certificate$`).test(r.url()) && r.request().method() === 'POST'),
      page.locator('[data-test="document-deposit-submit"]').click(),
    ])
    expect(pe.status(), 'document collaborateur déposé').toBeLessThan(300)
    m.deposer_collaborateur = await read(2)
    await expect(page.locator('[data-test="document-state-medical_certificate"]'), 'un seul état, transmis (ADR-810.1)').toContainText(/Transmis|Submitted/i)
    await page.screenshot({ path: `${OUT}/file-documents.jpg`, type: 'jpeg', quality: 55, fullPage: true })

    // ── 3. Valider (depuis la ligne : une action principale) ─────────────────────────────
    read = await meter(page)
    net.mark('valider')
    const [rev] = await Promise.all([
      page.waitForResponse(r => /\/documents\/medical_certificate\/review$/.test(r.url()) && r.request().method() === 'PUT'),
      page.locator('[data-test="document-approve-medical_certificate"]').click(),
    ])
    expect(rev.status(), 'validation').toBe(200)
    await expect(page.locator('[data-test="document-state-medical_certificate"]')).toContainText(/Approuvé|Approved/i)
    m.valider = await read(0)

    // ── 4. Demander un document (personne préremplie, sans compte) ───────────────────────
    read = await meter(page)
    net.mark('demander')
    await page.locator('[data-test="person-actions-menu"]').click()
    await page.locator('[data-test="person-action-request-document"]').click()
    const [rq] = await Promise.all([
      page.waitForResponse(r => /\/api\/company\/document-requests$/.test(r.url()) && r.request().method() === 'POST'),
      page.locator('.v-overlay--active .v-list-item', { hasText: /Permis/i }).first().click(),
    ])
    expect(rq.status(), 'demande créée').toBeLessThan(300)
    m.demander = await read(0)
    await expect(page.locator('[data-test="document-state-driving_license"]')).toContainText(/Demandé/i)

    // ── 5. Générer puis retrouver un contrat ────────────────────────────────────────────
    read = await meter(page)
    net.mark('générer un contrat')
    await page.locator('[data-test="person-actions-menu"]').click()
    await page.locator('[data-test="person-action-generate-contract"]').click()
    const dialog = page.locator('.v-overlay--active .v-card').last()
    await expect(dialog.locator('.v-select, .v-autocomplete').first()).toBeVisible()
    await page.waitForTimeout(600)
    const generate = dialog.getByRole('button', { name: /^Générer$/ })
    if (await generate.isDisabled()) {
      await dialog.locator('.v-select, .v-autocomplete').first().click()
      await page.locator('.v-overlay--active .v-list-item').first().click()
    }
    const [gen] = await Promise.all([
      page.waitForResponse(r => /\/api\/workforce\/.*generate/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }),
      generate.click(),
    ])
    expect(gen.status()).toBeLessThan(300)
    m.generer_contrat = await read(0)
    await page.keyboard.press('Escape')

    // ── 6. Préparer une signature (document présélectionné, rien n'est envoyé) ───────────
    await page.waitForTimeout(800)
    read = await meter(page)
    net.mark('préparer une signature')
    await page.locator('[data-test="person-actions-menu"]').click()
    await page.locator('[data-test="person-action-sign"]').click()
    const wizard = page.locator('.v-overlay--active .v-card').last()
    await expect(wizard, 'assistant de signature ouvert').toContainText(/signature/i)
    const preselected = await wizard.locator('.v-select__selection, .v-autocomplete__selection').first().innerText().catch(() => '')
    expect(preselected.trim().length, 'document déjà sélectionné').toBeGreaterThan(0)
    m.preparer_signature = await read(0)
    await page.screenshot({ path: `${OUT}/sign-preselected.jpg`, type: 'jpeg', quality: 55 })
    await page.keyboard.press('Escape')
    const sig = (await apiRequest(page, { path: '/api/workforce/signature?per_page=5' })).json
    expect(JSON.stringify(sig)).not.toContain(run.name('Docs'))

    // ── 7. Retrouver et télécharger (recherche serveur, vrai PDF) ───────────────────────
    await push(page, '/company/documents/todo')
    read = await meter(page)
    net.mark('recherche')
    const [found] = await Promise.all([
      page.waitForResponse(r => /\/api\/company\/documents\/search/.test(r.url()) && r.url().includes(encodeURIComponent(run.name('Docs')).slice(0, 12))),
      page.locator('[data-test="documents-search-input"] input').fill(run.name('Docs')),
    ])
    const results = (await found.json())
    expect(results.data.map(r => r.source).sort(), 'pièce déposée + contrat généré, rien d\'autre').toEqual(['generated', 'person'])
    await expect(page.locator('[data-test="documents-search-row-person"]')).toHaveCount(1)
    await expect(page.locator('[data-test="documents-search-row-generated"]')).toHaveCount(1)
    const [dl] = await Promise.all([
      page.waitForResponse(r => new RegExp(`/api/company/people/${ref}/documents/medical_certificate/download`).test(r.url())),
      page.locator('[data-test="documents-search-row-person"] [data-test="documents-search-download"]').click(),
    ])
    expect(dl.status()).toBe(200)
    const head = await page.evaluate(async path => {
      const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
      const r = await fetch(path, { credentials: 'include', headers: { 'X-Company-Id': decodeURIComponent(get('currentCompanyId') || '') } })

      return { status: r.status, type: r.headers.get('content-type'), start: new TextDecoder().decode((await r.arrayBuffer()).slice(0, 5)) }
    }, `/api/company/people/${ref}/documents/medical_certificate/download`)
    expect(head, 'vrai PDF servi par la route gardée').toMatchObject({ status: 200, start: '%PDF-' })
    m.retrouver_telecharger = await read(1)
    await page.screenshot({ path: `${OUT}/search.jpg`, type: 'jpeg', quality: 55 })
    // Le résultat ouvre l'endroit où vit le document.
    await page.locator('[data-test="documents-search-row-generated"]').click()
    await page.waitForURL(u => u.pathname === `/company/people/${ref}`)
    await expect(page.locator('[data-test="employee-generated-documents"] tbody tr').first(), 'contrat retrouvé dans le dossier').toBeVisible()

    // ── 8. Suivre une expiration ────────────────────────────────────────────────────────
    await push(page, '/company/documents/todo')
    read = await meter(page)
    net.mark('expiration')
    const expiring = page.locator('[data-test="documents-todo-expiring"], [data-test="documents-todo-expired"]').first()
    if (await expiring.count()) {
      await expiring.click()
      await page.waitForURL(/\/company\/documents\/compliance\?status=/)
      m.suivre_expiration = { ...(await read(0)), via: 'À traiter' }
    }
    else {
      await page.locator('[data-test="documents-tools"]').click()
      await page.locator('[data-test="documents-tool-compliance"]').click()
      await page.waitForURL(/\/company\/documents\/compliance/)
      m.suivre_expiration = { ...(await read(0)), via: 'Outils → Conformité (aucune échéance en cours)' }
    }

    // ── 9. Lancer une campagne (le formulaire s'ouvre ; rien n'est lancé) ────────────────
    await push(page, '/company/documents/todo')
    read = await meter(page)
    net.mark('campagne')
    await page.locator('[data-test="documents-new"]').click()
    await page.locator('[data-test="documents-new-campaign"]').click()
    await page.waitForURL(/\/company\/documents\/campaigns/)
    await expect(page.locator('.v-navigation-drawer--active, .v-overlay--active .v-card').last()).toBeVisible()
    m.lancer_campagne = await read(0)
    await page.screenshot({ path: `${OUT}/campaign-open.jpg`, type: 'jpeg', quality: 55 })
    await page.keyboard.press('Escape')

    // ── 10. Consulter un bulletin autorisé ──────────────────────────────────────────────
    const people = (await apiRequest(page, { path: '/api/company/people?per_page=100' })).json.data
    const me = people.find(r => r.email === 'owner@leezr.test')
    await push(page, `/company/people/${me.person_ref}`)
    read = await meter(page)
    net.mark('bulletin')
    await page.locator('[data-test="asn-tab-documents"]').click()
    await expect(page.locator('[data-test="person-section-payslips"]')).toBeVisible()
    m.consulter_bulletin = await read(0)
  }
  finally {
    // Le document d'entreprise déposé par cette exécution est retiré par l'opération de l'application.
    if (companyDocCode) m.company_document_removed = (await apiRequest(page, { method: 'DELETE', path: `/api/company/documents/${companyDocCode}` })).status
    m.teardown = run.teardown()
    m.network = net.bad
    fs.writeFileSync(`${OUT}/journeys-measures.json`, JSON.stringify(m, null, 1))
  }
  expect(net.bad, 'aucun refus réseau ni erreur JS').toEqual([])
})

const PROFILES = [
  { tag: 'rh', email: 'rh-nopay@leezr.test', note: 'RH sans droit paie' },
  { tag: 'dispatcher', email: 'admin@leezr.test', note: 'gestionnaire documentaire' },
  { tag: 'paie', email: 'pay@leezr.test', note: 'gestionnaire paie' },
  { tag: 'manager', email: 'manager@leezr.test', note: 'manager limité' },
]
for (const p of PROFILES) {
  test(`UX3 profil — ${p.tag} (${p.note})`, async ({ page }) => {
    test.setTimeout(4 * 60 * 1000)
    page.setDefaultTimeout(25000)
    const net = watch(page)
    await loginCompany(page, p.email)
    const nav = (await apiRequest(page, { path: '/api/nav' })).json
    const hasDocuments = nav.groups.some(g => g.key === 'documents')
    const result = { profile: p, hasDocuments, views: [], tools: [], newActions: [] }
    if (hasDocuments) {
      await push(page, '/company/documents/todo')
      await page.waitForTimeout(2000)
      result.views = await page.locator('[data-test^="documents-view-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('documents-view-', '')))
      expect(result.views.length, 'quatre vues au plus').toBeLessThanOrEqual(4)
      for (const v of result.views) {
        net.mark(`vue ${v}`)
        await page.locator(`[data-test="documents-view-${v}"]`).click()
        await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
        await page.waitForTimeout(900)
      }
      if (await page.locator('[data-test="documents-tools"]').count()) {
        await page.locator('[data-test="documents-tools"]').click()
        result.tools = await page.locator('[data-test^="documents-tool-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('documents-tool-', '')))
        await page.keyboard.press('Escape')
        for (const tool of result.tools) {
          net.mark(`outil ${tool}`)
          await push(page, `/company/documents/${tool}`)
          await page.waitForTimeout(900)
        }
      }
      await push(page, '/company/documents/todo')
      if (await page.locator('[data-test="documents-new"]').count()) {
        await page.locator('[data-test="documents-new"]').click()
        result.newActions = await page.locator('[data-test^="documents-new-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('documents-new-', '')))
        await page.keyboard.press('Escape')
      }
      // Recherche : uniquement ce que ce profil peut consulter (le serveur décide).
      net.mark('recherche')
      const served = (await apiRequest(page, { path: '/api/company/documents/search?per_page=50' }))
      result.search = { status: served.status, total: served.json?.meta?.total, sources: [...new Set((served.json?.data || []).map(r => r.source))] }
      await page.screenshot({ path: `${OUT}/hub-${p.tag}.jpg`, type: 'jpeg', quality: 55 })
    }
    result.network = net.bad
    fs.writeFileSync(`${OUT}/result-${p.tag}.json`, JSON.stringify(result, null, 1))
    expect(net.bad, 'aucun refus réseau ni erreur JS').toEqual([])
  })
}

test('UX3 entreprise sans Workforce : vues documentaires seules, anciens liens valides', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(25000)
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
    await page.goto('/company/documents/overview', { waitUntil: 'domcontentloaded' })
    await expect(page.locator('[data-test="documents-view-todo"]')).toBeVisible()
    await page.waitForTimeout(1500)
    const views = await page.locator('[data-test^="documents-view-"]').evaluateAll(els => els.map(e => e.getAttribute('data-test').replace('documents-view-', '')))
    expect(views, 'pas de vue Signatures sans le module').not.toContain('signatures')
    for (const [old, now] of [['vault', 'company'], ['requests', 'people'], ['hr', 'people']]) {
      await push(page, `/company/documents/${old}`)
      expect(new URL(page.url()).pathname, `ancien lien ${old}`).toBe(`/company/documents/${now}`)
    }
    const served = (await apiRequest(page, { path: '/api/company/documents/search' })).json
    expect(served.meta.sources, 'la source des documents générés n\'est pas interrogée').not.toContain('generated')
    await page.screenshot({ path: `${OUT}/hub-no-workforce.jpg`, type: 'jpeg', quality: 55 })
    fs.writeFileSync(`${OUT}/result-no-workforce.json`, JSON.stringify({ company: other.name, views, sources: served.meta.sources, network: net.bad }, null, 1))
    expect(net.bad).toEqual([])
  }
  finally { await sw(origin.id) }
})

test.describe('mobile', () => {
  test.use({ viewport: { width: 390, height: 844 } })
  test('UX3 mobile : Documents et dossier sans débordement, actions dans l\'écran', async ({ page }) => {
    test.setTimeout(3 * 60 * 1000)
    page.setDefaultTimeout(25000)
    const net = watch(page)
    await loginCompany(page, 'owner@leezr.test')
    const overflow = () => page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
    for (const v of ['todo', 'company', 'people', 'signatures']) {
      await page.goto(`/company/documents/${v}`, { waitUntil: 'domcontentloaded' })
      await page.waitForTimeout(2500)
      expect(await overflow(), `vue ${v} sans débordement`).toBe(false)
    }
    await page.goto('/company/documents/todo', { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(2000)
    for (const sel of ['[data-test="documents-new"]', '[data-test="documents-tools"]']) {
      const box = await page.locator(sel).boundingBox()
      expect(box && box.x >= 0 && box.x + box.width <= 390, `${sel} dans l'écran`).toBe(true)
    }
    await page.screenshot({ path: `${OUT}/mobile-todo.jpg`, type: 'jpeg', quality: 55 })
    const rows = (await apiRequest(page, { path: '/api/company/people?per_page=100' })).json.data
    const linked = rows.find(r => r.employee_id && r.user_id && r.email !== 'owner@leezr.test')
    await page.goto(`/company/people/${linked.person_ref}?tab=documents`, { waitUntil: 'domcontentloaded' })
    await expect(page.locator('[data-test="person-section-documents"]')).toBeVisible()
    await page.waitForTimeout(1500)
    expect(await overflow(), 'dossier documentaire sans débordement').toBe(false)
    await page.locator('[data-test="person-section-documents"]').scrollIntoViewIfNeeded()
    // Chaque action de ligne est DANS l'écran (état + action principale + menu).
    for (const el of await page.locator('[data-test="person-section-documents"] [data-test^="document-upload-"], [data-test="person-section-documents"] [data-test^="document-approve-"], [data-test="person-section-documents"] [data-test^="document-state-"]').all()) {
      const box = await el.boundingBox()
      expect(box && box.x >= 0 && box.x + box.width <= 390, 'action de ligne dans l\'écran').toBe(true)
    }
    await page.screenshot({ path: `${OUT}/mobile-file-documents.jpg`, type: 'jpeg', quality: 55 })
    expect(net.bad).toEqual([])
  })
})
