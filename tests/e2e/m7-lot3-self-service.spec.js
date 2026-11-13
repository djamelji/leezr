// @ts-check
// ADR-769 (M7 LOT 3) — parcours NAVIGATEUR du self-service unifié, avec vérification RÉSEAU :
// chaque parcours enregistre toutes les réponses de l'API et échoue au moindre 4xx/5xx, et vérifie
// qu'aucune API Workforce n'est appelée quand la capacité n'existe pas.
//
// Jeu de données local attendu (seeders dev) :
//   - trial@leezr.test  : entreprise SANS module Workforce ;
//   - admin@leezr.test  : administrateur de l'entreprise 1, qui possède AUSSI une fiche salarié ;
//   - owner@leezr.test  : propriétaire (crée la demande de signature par le moteur réel).
// Le bulletin de démonstration est posé par `E2E_PAYSLIP_ID` (voir le rapport du lot).
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/m7-lot3'

async function settle(page, ms = 1400) {
  await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {})
  await page.waitForTimeout(ms)
}

/** Enregistre les appels API : `calls` (toutes les URL) et `errors` (4xx / 5xx). */
function watchApi(page) {
  const calls = []
  const errors = []

  page.on('response', r => {
    const url = r.url()
    if (!url.includes('/api/')) return
    const path = new URL(url).pathname
    calls.push(path)
    // Le rapporteur d'erreurs (sendBeacon, sans jeton CSRF) n'est pas une API métier : les vraies
    // erreurs d'exécution sont captées ci-dessous par `pageerror` et font échouer le parcours.
    if (path === '/api/runtime-error') return
    if (r.status() >= 400) errors.push(`${r.status()} ${r.request().method()} ${path}`)
  })
  page.on('pageerror', e => errors.push(`pageerror ${String(e).slice(0, 200)}`))

  return { calls, errors, workforce: () => calls.filter(p => p.startsWith('/api/workforce/')) }
}

test.describe.configure({ mode: 'serial' })

test('entreprise SANS Workforce : self-service Core complet, aucune requête Workforce, aucun 403', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const api = watchApi(page)

  await loginCompany(page, 'trial@leezr.test')
  for (const path of ['/dashboard', '/home', '/company/my-space/overview', '/company/my-space/documents', '/company/my-space/payslips', '/account-settings/account', '/account-settings/security']) {
    await page.goto(path, { waitUntil: 'domcontentloaded' })
    await settle(page)
  }

  const space = await apiRequest(page, { path: '/api/profile/space' })
  expect(space.status).toBe(200)
  expect(space.json.capabilities, 'aucune capacité Workforce annoncée').toMatchObject({ employment: false, time_clock: false, planning: false, leave: false, documents: true, payslips: true })

  await page.goto('/company/my-space/overview', { waitUntil: 'domcontentloaded' })
  await settle(page)
  for (const key of ['documents', 'payslips', 'profile', 'security'])
    await expect(page.locator(`[data-test="my-space-entry-${key}"]`), `rubrique ${key}`).toBeVisible()
  for (const key of ['activity', 'signatures'])
    await expect(page.locator(`[data-test="my-space-entry-${key}"]`), `rubrique ${key} absente`).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Mon espace' }).first()).toBeVisible()
  await expect(page.getByRole('link', { name: 'Mon activité' })).toHaveCount(0)

  // Une rubrique absente reste inaccessible par URL (retour à l'aperçu, sans appel Workforce).
  await page.goto('/company/my-space/signatures', { waitUntil: 'domcontentloaded' })
  await expect(page).toHaveURL(/\/company\/my-space\/overview/, { timeout: 45000 })
  await settle(page)

  await page.screenshot({ path: `${DIR}/01-no-workforce-my-space-light.png`, fullPage: true })
  await page.goto('/home', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await page.screenshot({ path: `${DIR}/02-no-workforce-home-light.png`, fullPage: true })

  expect(api.workforce(), 'AUCUN appel à une API Workforce').toEqual([])
  expect(api.errors, 'aucun 4xx/5xx sur le parcours').toEqual([])
})

test('administrateur AUSSI salarié : accueil, Mon espace, documents, bulletins — sans erreur réseau', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const api = watchApi(page)

  await loginCompany(page, 'admin@leezr.test')
  const space = await apiRequest(page, { path: '/api/profile/space' })
  expect(space.json.has_employee, 'fiche salarié explicitement liée').toBe(true)
  expect(space.json.capabilities).toMatchObject({ employment: true, time_clock: true, documents: true, payslips: true, signatures: true })

  // Il administre toujours son entreprise…
  await page.goto('/company/people', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('[data-test="people-new"]'), 'reste administrateur').toBeVisible()

  // …et dispose de son accueil personnel (pointage, planning).
  await page.goto('/home', { waitUntil: 'domcontentloaded' })
  await settle(page, 2000)
  await expect(page.getByText('Mon pointage')).toBeVisible()
  await page.screenshot({ path: `${DIR}/03-admin-employee-home-light.png`, fullPage: true })

  await page.goto('/company/my-space/overview', { waitUntil: 'domcontentloaded' })
  await settle(page)
  for (const key of ['documents', 'payslips', 'signatures', 'activity', 'profile', 'security'])
    await expect(page.locator(`[data-test="my-space-entry-${key}"]`), `rubrique ${key}`).toBeVisible()
  await page.screenshot({ path: `${DIR}/04-my-space-overview-light.png`, fullPage: true })

  // Documents : l'ancien lien des notifications redirige vers Mon espace.
  await page.goto('/account-settings/documents', { waitUntil: 'domcontentloaded' })
  await expect(page).toHaveURL(/\/company\/my-space\/documents/, { timeout: 30000 })
  await settle(page)
  await page.screenshot({ path: `${DIR}/05-my-documents-light.png`, fullPage: true })

  // Bulletins : uniquement les siens, classés par année ; téléchargement réel.
  await page.goto('/company/my-space/payslips', { waitUntil: 'domcontentloaded' })
  await settle(page)
  const mine = await apiRequest(page, { path: '/api/profile/payslips' })
  expect(mine.status).toBe(200)
  await expect(page.locator('[data-test="my-payslips"]')).toBeVisible()
  if (mine.json.payslips.length) {
    const first = mine.json.payslips.find(p => p.can_download)
    await expect(page.locator(`[data-test="my-payslip-${first.id}"]`)).toBeVisible()
    const [download] = await Promise.all([
      page.waitForResponse(r => r.url().includes(`/api/profile/payslips/${first.id}/download`), { timeout: 20000 }),
      page.locator(`[data-test="my-payslip-${first.id}"]`).getByRole('button', { name: 'Télécharger' }).click(),
    ])
    expect(download.status(), 'téléchargement de MON bulletin').toBe(200)
  }
  await page.screenshot({ path: `${DIR}/06-my-payslips-light.png`, fullPage: true })

  // L'activité Workforce reste sur sa page dédiée.
  await page.goto('/company/my-space/overview', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await page.locator('[data-test="my-space-entry-activity"]').click()
  await expect(page).toHaveURL(/\/company\/workforce\/me/, { timeout: 30000 })
  await settle(page)

  expect(api.errors, 'aucun 4xx/5xx sur le parcours').toEqual([])
})

test('signatures : demande créée par le moteur réel → « Mes signatures » → cérémonie existante', async ({ page, browser }) => {
  test.setTimeout(4 * 60 * 1000)

  // ── Le propriétaire crée une vraie demande de signature pour la fiche de l'administrateur-salarié ──
  await loginCompany(page, 'owner@leezr.test')
  const people = await apiRequest(page, { path: '/api/company/people?search=admin@leezr.test' })
  const target = people.json.data[0]
  expect(target?.employee_id, 'admin@leezr.test possède une fiche salarié').toBeTruthy()
  const eligible = await apiRequest(page, { path: '/api/workforce/signature/eligible-documents' })
  const eligibleDocs = (eligible.json?.data || []).filter(d => d.subject_type === 'employee')
  test.skip(!eligibleDocs.length, 'aucun document RH éligible à la signature sur cette base')
  const doc = eligibleDocs[0]
  const created = await apiRequest(page, {
    method: 'POST', path: '/api/workforce/signature',
    body: { generated_document_id: doc.id, signers: [{ role: 'employee', name: target.name, email: 'admin@leezr.test', order: 1, party_type: 'employee', party_id: target.employee_id }] },
  })
  expect(created.status, `création de la demande : ${JSON.stringify(created.json)?.slice(0, 200)}`).toBe(201)
  const requestId = created.json.id

  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } })
  const me = await ctx.newPage()
  const api = watchApi(me)
  try {
    await loginCompany(me, 'admin@leezr.test')

    // L'accueil signale la signature à effectuer.
    await me.goto('/home', { waitUntil: 'domcontentloaded' })
    await settle(me, 2000)
    await expect(me.getByText('Signatures à effectuer')).toBeVisible()

    await me.goto('/company/my-space/signatures', { waitUntil: 'domcontentloaded' })
    await settle(me)
    const list = await apiRequest(me, { path: '/api/workforce/signature/me' })
    const row = list.json.signatures.find(s => s.request_id === requestId)
    expect(row, 'la demande apparaît dans MES signatures').toBeTruthy()
    expect([row.state, row.can_sign]).toEqual(['to_sign', true])
    await expect(me.locator(`[data-test="my-signature-state-${row.id}"]`)).toContainText('À signer')
    await me.screenshot({ path: `${DIR}/07-my-signatures-light.png`, fullPage: true })
    expect(api.errors, 'aucun 4xx/5xx avant la cérémonie').toEqual([])

    // « Signer » ouvre la cérémonie EXISTANTE (/sign/…) — rien n'est signé par cet écran.
    const [opened] = await Promise.all([
      me.waitForResponse(r => r.url().includes(`/api/workforce/signature/me/${row.id}/open`) && r.request().method() === 'POST', { timeout: 20000 }),
      me.locator(`[data-test="my-signature-${row.id}"] [data-test="my-signature-open"]`).click(),
    ])
    expect(opened.status()).toBe(200)
    await expect(me).toHaveURL(/\/sign\/[A-Za-z0-9_-]+/, { timeout: 20000 })
    await settle(me, 2000)
    await me.screenshot({ path: `${DIR}/08-signing-ceremony-light.png`, fullPage: true })

    const after = await apiRequest(page, { path: `/api/workforce/signature/${requestId}` })
    expect(after.json.signers[0].status, "ouvrir la cérémonie n'a rien signé").toBe('invited')
  }
  finally {
    await ctx.close()
    // Nettoyage : la demande de test est annulée par le moteur (aucune suppression directe).
    await apiRequest(page, { method: 'POST', path: `/api/workforce/signature/${requestId}/cancel`, body: { reason: 'E2E M7 LOT 3' } })
  }

  const final = await apiRequest(page, { path: `/api/workforce/signature/${requestId}` })
  expect(final.json.status).toBe('cancelled')
})

test('captures — Mon espace et accueil (mobile, sombre)', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const api = watchApi(page)

  await loginCompany(page, 'admin@leezr.test')
  const shots = [['/home', 'home'], ['/company/my-space/overview', 'my-space'], ['/company/my-space/payslips', 'my-payslips'], ['/company/my-space/signatures', 'my-signatures'], ['/company/my-space/documents', 'my-documents']]

  await page.emulateMedia({ colorScheme: 'dark' })
  await page.setViewportSize({ width: 1440, height: 1000 })
  for (const [path, name] of shots) {
    await page.goto(path, { waitUntil: 'domcontentloaded' })
    await settle(page, 1800)
    await page.screenshot({ path: `${DIR}/10-${name}-dark.png`, fullPage: true })
  }
  for (const scheme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: scheme })
    await page.setViewportSize({ width: 390, height: 844 })
    for (const [path, name] of shots) {
      await page.goto(path, { waitUntil: 'domcontentloaded' })
      await settle(page, 1800)
      await page.screenshot({ path: `${DIR}/20-${name}-mobile-${scheme}.png`, fullPage: true })
    }
  }
  expect(api.errors, 'aucun 4xx/5xx pendant les captures').toEqual([])
})
