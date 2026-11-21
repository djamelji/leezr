// @ts-check
// Leezr 4.2 M3.1 (ADR-840) — browser recipe on the MARKED clone only (global-setup, ADR-831).
// PHASE=before : historical driver roles as they are (directory readable — the defect).
// PHASE=after  : after `rbac:driver-role-remediate --apply` ON THE CLONE : deliveries kept, directory / settings /
//               company vault refused, own documents kept, other company refused ; pending rights ; manager read-only.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M3.1 recipe only (AUDIT_PASSWORD_FILE)')
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const PHASE = process.env.PHASE || 'after'
const OUT = 'docs/bmad/leezr-4-2-audit/m3-1'
const prefix = PHASE === 'before' ? 'avant' : 'apres'
const shot = async (page, name) => { await page.waitForTimeout(500); await page.screenshot({ path: `${OUT}/${prefix}-${name}.jpg`, type: 'jpeg', quality: 60 }) }

function watch(page) {
  const problems = []
  page.on('pageerror', e => problems.push(`js ${e.message}`))
  page.on('response', r => {
    if (r.status() >= 400 && r.url().includes('/api/') && !r.url().includes('/realtime/') && !(r.status() === 429 && r.url().includes('/api/public/')))
      problems.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')}`)
  })

  return problems
}

const api = (page, method, url, companyId, body = null) => page.evaluate(async ([m, u, c, b]) => {
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  const r = await fetch(u, { method: m, credentials: 'include', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Company-Id': String(c) }, body: b ? JSON.stringify(b) : undefined })

  return { status: r.status, text: await r.text() }
}, [method, url, companyId, body])

const open = async (page, path) => { await page.goto(path, { waitUntil: 'networkidle' }); await page.waitForTimeout(800) }

test.beforeEach(({ page }) => { page.setDefaultTimeout(15000); fs.mkdirSync(OUT, { recursive: true }) })

test('Chauffeur historique (société 1) — annuaire, réglages, livraisons, isolation', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginCompany(page, 'bob@leezr.test', PASSWORD)
  const members = await api(page, 'GET', '/api/company/members', 1)
  if (PHASE === 'before') {
    expect(members.status, 'BEFORE : the historical driver reads the directory (defect SEC-04)').toBe(200)
    await open(page, '/company/people')
    await shot(page, 'chauffeur-societe1-annuaire')

    return
  }
  const problems = watch(page)
  await open(page, '/company/my-deliveries')
  await expect(page.getByTestId('company-forbidden')).toHaveCount(0)
  await expect(page.getByTestId('company-access-pending')).toHaveCount(0)
  expect((await api(page, 'GET', '/api/my-deliveries', 1)).status, 'deliveries kept').toBe(200)
  await shot(page, 'chauffeur-societe1-livraisons')
  expect(members.status, 'directory refused').toBe(403)
  expect(members.text).not.toContain('@leezr.test')
  await open(page, '/company/people')
  await expect(page.getByTestId('company-forbidden')).toBeVisible()
  await shot(page, 'chauffeur-societe1-annuaire-refuse')
  await open(page, '/company/profile/overview')
  await expect(page.getByTestId('company-forbidden')).toBeVisible()
  const other = await api(page, 'GET', '/api/my-deliveries', 105)
  expect(other.status, 'another company refused').toBe(403)
  expect(problems.filter(p => !/^403 GET \/api\/(company\/members|my-deliveries)$/.test(p))).toEqual([])
})

test('Chauffeur historique (société 101) — coffre documentaire retiré, ses documents conservés', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginCompany(page, 'e2e.811.driver.42700@leezr.invalid', PASSWORD)
  const vault = await api(page, 'GET', '/api/company/documents', 101)
  if (PHASE === 'before') {
    expect((await api(page, 'GET', '/api/company/members', 101)).status).toBe(200)
    expect(vault.status, 'BEFORE : the driver reads the company vault').toBe(200)
    await open(page, '/company/documents')
    await shot(page, 'chauffeur-societe101-documents')

    return
  }
  const problems = watch(page)
  expect((await api(page, 'GET', '/api/company/members', 101)).status).toBe(403)
  expect(vault.status, 'company vault refused').toBe(403)
  await open(page, '/company/documents')
  await expect(page.getByTestId('company-forbidden')).toBeVisible()
  expect((await api(page, 'GET', '/api/profile/documents', 101)).status, 'own documents kept').toBe(200)
  await open(page, '/company/my-space/documents')
  await expect(page.getByTestId('company-forbidden')).toHaveCount(0)
  await shot(page, 'chauffeur-societe101-mes-documents')
  expect((await api(page, 'GET', '/api/my-deliveries', 101)).status).toBe(200)
  expect(problems.filter(p => !/^403 GET \/api\/company\/(members|documents)$/.test(p))).toEqual([])
})

test('Droits en attente de validation — état distinct, jamais accordés, ouverts après confirmation', async ({ page, browser }) => {
  test.skip(PHASE === 'before')
  test.setTimeout(4 * 60 * 1000)
  const problems = watch(page)
  await loginCompany(page, 'driver843@audit842.test', PASSWORD)
  await open(page, '/company/my-deliveries')
  await expect(page.getByTestId('company-access-pending')).toContainText("attend la validation d'un administrateur")
  await expect(page.getByTestId('company-forbidden')).toHaveCount(0)
  expect((await api(page, 'GET', '/api/my-deliveries', 105)).status, 'a pending right is never granted').toBe(403)
  await shot(page, 'droits-en-attente')
  // a right the role does NOT hold stays a plain refusal
  await open(page, '/company/people')
  await expect(page.getByTestId('company-forbidden')).toBeVisible()

  // the owner confirms (grouped review, existing engine, audited)
  const ownerCtx = await browser.newContext()
  const owner = await ownerCtx.newPage()
  await loginCompany(owner, 'camille.recette834@fictif.test', PASSWORD)
  await open(owner, '/company/people')
  const review = JSON.parse((await api(owner, 'GET', '/api/company/pending-access', 105)).text)
  expect((await api(owner, 'POST', '/api/company/pending-access/confirm', 105, { fingerprint: review.fingerprint })).status).toBe(200)
  await ownerCtx.close()

  await open(page, '/company/my-deliveries')
  await expect(page.getByTestId('company-access-pending')).toHaveCount(0)
  expect((await api(page, 'GET', '/api/my-deliveries', 105)).status).toBe(200)
  expect(problems.filter(p => !/^403 GET \/api\/(my-deliveries|company\/members)$/.test(p))).toEqual([])
})

test('Manager Workforce en lecture seule — réglages lisibles, aucune commande interdite', async ({ page }) => {
  test.skip(PHASE === 'before')
  test.setTimeout(3 * 60 * 1000)
  const problems = watch(page)
  await loginCompany(page, 'manager843@audit842.test', PASSWORD)
  await open(page, '/company/workforce/settings/general')
  await expect(page.locator('[data-test="workforce-settings-readonly"]')).toContainText('Lecture seule')
  await expect(page.getByRole('button', { name: /Enregistrer/ })).toHaveCount(0)
  await expect(page.getByRole('button', { name: /assistant/i })).toHaveCount(0)
  await shot(page, 'manager-reglages-general')
  await open(page, '/company/workforce/settings/worktime')
  await expect(page.locator('[data-test="working-time-setup"]')).toBeVisible()
  await expect(page.locator('[data-test="wt-save"], [data-test="wt-confirm-convention"]')).toHaveCount(0)
  await shot(page, 'manager-reglages-temps-de-travail')
  expect((await api(page, 'PUT', '/api/workforce/working-time-setup', 105, { values: {} })).status, 'server still refuses').toBe(403)
  expect(problems.filter(p => p !== '403 PUT /api/workforce/working-time-setup')).toEqual([])
})
