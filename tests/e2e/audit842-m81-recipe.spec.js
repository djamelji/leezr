// @ts-check
// Leezr 4.2 M8.1 (ADR-846) — reliable data recovery, browser recipe on the MARKED clone, fictitious company 106 only.
// Fixture : scripts/audit842-m8-fixture.php (reset | m81 | suspend | expire). Archives are REALLY opened (unzip).
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M8.1 recipe only (AUDIT_PASSWORD_FILE)')

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m8/m81'
const OWNER = 'owner.at4f8@audit842.test'
const env = { ...process.env, DB_DATABASE: process.env.E2E_DB_DATABASE || 'leezr_audit842', CACHE_PREFIX: 'leezr_audit842-', REDIS_PREFIX: 'leezr_audit842-' }
const fixture = (...args) => execFileSync('php', ['scripts/audit842-m8-fixture.php', ...args], { env }).toString()
const shot = async (page, name) => { await page.waitForTimeout(500); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60, fullPage: true }) }

const api = (page, url, { method = 'GET', company = '106', body } = {}) => page.evaluate(async ({ url, method, company, body }) => {
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  const r = await fetch(url, { method, credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Company-Id': company, 'X-XSRF-TOKEN': xsrf }, body: body ? JSON.stringify(body) : undefined })
  let json = null
  try { json = await r.json() }
  catch { /* zip or empty */ }

  return { status: r.status, json }
}, { url, method, company, body })

const openClosure = async page => {
  await page.goto('/company/billing/closure', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="company-closure"]')).toBeVisible()
}

const requestExport = async page => {
  await page.locator('[data-test="closure-export-request"]').click()
  await page.locator('[data-test="closure-export-password"] input').fill(PASSWORD)
  await page.locator('[data-test="closure-export-confirm"]').click()
  await expect(page.locator('[data-test="closure-message"]')).toContainText('en préparation')

  const chip = page.locator('[data-test^="closure-export-status-"]').first()

  await expect(chip).not.toHaveText(/En attente|En préparation/)

  return { chip, id: (await chip.getAttribute('data-test')).replace('closure-export-status-', '') }
}

const downloadZip = async (page, id, name) => {
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator(`[data-test="closure-export-download-${id}"]`).click()])
  const zip = path.join(OUT, name)

  fs.copyFileSync(await dl.path(), zip)

  return { list: execFileSync('unzip', ['-Z1', zip]).toString().trim().split('\n'), read: f => execFileSync('unzip', ['-p', zip, f]).toString('utf8') }
}

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles', 'm2') // ADR-850 : member842 and the base accounts, without relying on another recipe
  fixture('reset')
  fixture('m81', process.env.AUDIT_PASSWORD_FILE)
})
test.afterAll(() => { fixture('reset') }) // ADR-850 : the company is never left suspended, even after a failure

test('Export partiel affiché avant téléchargement, puis export pendant suspension (seul ce parcours est ouvert)', async ({ page, browser }) => {
  test.setTimeout(6 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await loginCompany(page, OWNER, PASSWORD)
  await openClosure(page)

  // 4 — an archive with a missing file is PARTIAL, never « complete » ; what is missing is shown before downloading
  const first = await requestExport(page)

  await expect(first.chip).toHaveText('Export partiel')

  const missing = page.locator(`[data-test="closure-export-missing-${first.id}"]`)

  await expect(missing).toContainText('Cet export est incomplet')
  await expect(missing).toContainText('Documents (1) — fichier introuvable dans le stockage')
  await expect(page.locator(`[data-test="closure-export-download-${first.id}"]`)).toHaveText(/Télécharger l'export partiel/)
  await shot(page, 'A1-export-partiel-avant-telechargement')

  const a = await downloadZip(page, first.id, 'exemple-export-partiel.zip')
  const manifest = JSON.parse(a.read('manifest.json'))

  expect(manifest.outcome).toBe('partial')
  expect(manifest.missing).toEqual([{ part: 'documents', reason: 'missing_file', count: 1 }])
  expect(a.read('documents/index.csv')).toContain('missing_file')
  expect(a.list).toContain('workforce/employees.csv') // several data types still delivered
  fs.writeFileSync(path.join(OUT, 'exemple-manifest-partiel.json'), JSON.stringify(manifest, null, 2))

  // 1 — the company is suspended : the owner still reaches the recovery page, requests (password) and downloads
  fixture('suspend')
  fixture('expire') // ages the previous request past the cooldown (the partial archive itself stays available)
  await openClosure(page)
  await expect(page.locator('[data-test="closure-suspended"]')).toContainText('Votre entreprise est suspendue')
  await expect(page.locator(`[data-test="closure-export-status-${first.id}"]`)).toHaveText('Export partiel')

  const second = await requestExport(page)

  await expect(second.chip).toHaveText('Export partiel')

  const b = await downloadZip(page, second.id, 'exemple-export-suspendu.zip')

  expect(JSON.parse(b.read('manifest.json')).company.id).toBe(106)
  await shot(page, 'A2-societe-suspendue-export')

  // …but nothing else opens, and nothing is reactivated
  const closed = {}
  for (const url of ['/api/shipments', '/api/company/members', '/api/workforce/departments', '/api/modules'])
    closed[url] = (await api(page, url)).status
  expect(closed).toEqual({ '/api/shipments': 403, '/api/company/members': 403, '/api/workforce/departments': 403, '/api/modules': 403 })
  expect((await api(page, '/api/workforce/departments', { method: 'POST', body: { name: 'X' } })).status).toBe(403)
  expect((await api(page, '/api/company/lifecycle')).json.operational.state).toBe('suspended')

  // 9 — another tenant : the owner of 106 cannot reach the export through company 105
  expect((await api(page, `/api/company/data-exports/${second.id}/download`, { company: '105' })).status).toBe(403)

  // 2 — suspended company, member WITHOUT the right
  const ctx = await browser.newContext()
  const m = await ctx.newPage()

  m.setDefaultTimeout(20000)
  await loginCompany(m, 'member842@audit842.test', PASSWORD)
  expect((await api(m, '/api/company/lifecycle')).status).toBe(403)
  expect((await api(m, '/api/company/data-exports', { method: 'POST', body: { password: PASSWORD } })).status).toBe(403)
  expect((await api(m, `/api/company/data-exports/${second.id}/download`)).status).toBe(403)
  await ctx.close()

  // 8 — no session : refused, the archive never leaves
  const anon = await browser.newContext()
  const p = await anon.newPage()

  await p.goto('/login')
  expect((await api(p, `/api/company/data-exports/${second.id}/download`)).status).toBe(401)
  expect((await api(p, '/api/company/data-exports', { method: 'POST', body: { password: PASSWORD } })).status).toBe(401)
  await anon.close()

  // 7 — revoked link (expired is covered by the M8 recipe)
  fixture('reset')
})

test('Procédure exceptionnelle Platform : droit dédié, justification, second opérateur, exécution désactivée', async ({ browser }) => {
  test.setTimeout(5 * 60 * 1000)


  // an operator without the dedicated permission : no section, API refused
  const v = await browser.newContext()
  const viewer = await v.newPage()

  viewer.setDefaultTimeout(20000)
  await loginPlatform(viewer, 'viewer842@platform.test', PASSWORD)
  await viewer.goto('/platform/companies/106/billing', { waitUntil: 'networkidle' })
  await expect(viewer.locator('[data-testid="company-lifecycle"]')).toBeVisible()
  await expect(viewer.locator('[data-testid="lifecycle-exceptional"]')).toHaveCount(0)

  const refused = await viewer.evaluate(async () => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')

    return (await fetch('/api/platform/companies/106/data-exports/exceptional', { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }, body: '{}' })).status
  })

  expect(refused).toBe(403)
  await v.close()

  // operator A records the request
  const ca = await browser.newContext()
  const opA = await ca.newPage()

  opA.setDefaultTimeout(20000)
  await loginPlatform(opA, 'opa846@platform.test', PASSWORD)
  await opA.goto('/platform/companies/106/billing', { waitUntil: 'networkidle' })

  const card = opA.locator('[data-testid="company-lifecycle"]')

  await expect(card.locator('[data-testid="lifecycle-exceptional-disabled"]')).toContainText('Exécution désactivée en attente de validation')
  await card.locator('[data-testid="lifecycle-exceptional-open"]').click()
  await opA.locator('[data-testid="lifecycle-exceptional-recipient"]').click()
  await opA.locator('.v-overlay--active .v-list-item', { hasText: 'member842@audit842.test' }).click()
  await opA.locator('[data-testid="lifecycle-exceptional-justification"] textarea').fill('trop court')
  await opA.locator('[data-testid="lifecycle-exceptional-authority"] textarea').fill('Mandat de recette vérifié')
  await opA.locator('[data-testid="lifecycle-exceptional-submit"]').click()
  await expect(opA.locator('[data-testid="lifecycle-exceptional-justification"]')).toContainText('30')
  await opA.locator('[data-testid="lifecycle-exceptional-justification"] textarea').fill('Recette M8.1 — propriétaire indisponible, demande fictive du dossier support RECETTE-846')
  await opA.locator('[data-testid="lifecycle-exceptional-submit"]').click()

  const row = card.locator('[data-testid^="lifecycle-export-"]').first()

  await expect(row).toContainText('En attente de validation')
  await expect(row).toContainText('Exceptionnel, remis à Membre Recette')

  const id = (await row.getAttribute('data-testid')).replace('lifecycle-export-', '')

  await expect(card.locator(`[data-testid="lifecycle-approve-${id}"]`)).toBeDisabled() // never self-approved
  await card.scrollIntoViewIfNeeded()
  await opA.screenshot({ path: `${OUT}/apres-B1-demande-exceptionnelle.jpg`, type: 'jpeg', quality: 60 })
  await ca.close()

  // operator B approves : execution OFF → refused with an explanation, nothing built
  const cb = await browser.newContext()
  const opB = await cb.newPage()

  opB.setDefaultTimeout(20000)
  await loginPlatform(opB, 'opb846@platform.test', PASSWORD)
  await opB.goto('/platform/companies/106/billing', { waitUntil: 'networkidle' })

  const cardB = opB.locator('[data-testid="company-lifecycle"]')

  await cardB.locator(`[data-testid="lifecycle-approve-${id}"]`).click()
  await expect(cardB.locator('[data-testid="lifecycle-notice"]')).toContainText('désactivée')
  await expect(cardB.locator(`[data-testid="lifecycle-export-${id}"]`)).toContainText('En attente de validation')
  await cardB.scrollIntoViewIfNeeded()
  await opB.screenshot({ path: `${OUT}/apres-B2-validation-bloquee.jpg`, type: 'jpeg', quality: 60 })


  // no Platform download route
  const dl = await opB.evaluate(async id => (await fetch(`/api/platform/companies/106/data-exports/${id}/download`, { credentials: 'include', headers: { Accept: 'application/json' } })).status, id)

  expect([404, 405]).toContain(dl)
  await cardB.locator(`[data-testid="lifecycle-reject-${id}"]`).click()
  await expect(cardB.locator(`[data-testid="lifecycle-export-${id}"]`)).toContainText('Refusé')
  await opB.screenshot({ path: `${OUT}/apres-B3-demande-refusee.jpg`, type: 'jpeg', quality: 60 })
  await cb.close()
})
