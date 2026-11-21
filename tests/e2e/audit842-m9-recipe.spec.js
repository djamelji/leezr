// @ts-check
// Leezr 4.2 M9 (ADR-848) — non-regression of the refactored areas, browser recipe on the MARKED clone, fictitious
// company 106 only. Every screen is loaded for real ; every API refusal or JS error is collected ; actions are
// reversible (a draft shift created then undone, redone, undone again). Invoice PDFs are really downloaded and read.
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M9 recipe only (AUDIT_PASSWORD_FILE)')

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m9'
const OWNER = 'owner.at4f8@audit842.test'
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 }) }

function watch(page) {
  const problems = []

  page.on('pageerror', e => problems.push(`js ${e.message}`))
  page.on('response', r => {
    const u = r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')
    if (r.status() >= 400 && u.startsWith('/api/') && !u.includes('/realtime/') && !(r.status() === 429 && u.startsWith('/api/public/')))
      problems.push(`${r.status()} ${r.request().method()} ${u}`)
  })

  return problems
}

const api = (page, url, { method = 'GET', body, platform = false, raw = false } = {}) => page.evaluate(async ({ url, method, body, platform, raw }) => {
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  const headers = { 'Accept': raw ? 'application/pdf' : 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }
  if (!platform) headers['X-Company-Id'] = '106'
  const r = await fetch(url, { method, credentials: 'include', headers, body: body ? JSON.stringify(body) : undefined })
  if (raw) return { status: r.status, head: new TextDecoder().decode((await r.arrayBuffer()).slice(0, 5)) }
  let json = null
  try { json = await r.json() }
  catch { /* empty */ }

  return { status: r.status, json }
}, { url, method, body, platform, raw })

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  execFileSync('php', ['scripts/audit842-m9-fixture.php'], { env: { ...process.env, DB_DATABASE: process.env.E2E_DB_DATABASE || 'leezr_audit842' } })
})

test('Company : planification (lectures, opérations annulables), signature, documents, collaborateurs, facture PDF', async ({ page }) => {
  test.setTimeout(6 * 60 * 1000)
  page.setDefaultTimeout(20000)

  const problems = watch(page)

  await loginCompany(page, OWNER, PASSWORD)

  // planning screens (ShiftQueryController) and the reversible operation chain (ShiftController + ShiftOperationController)
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  await shot(page, 'A1-planning')

  const monday = '2026-12-07'
  for (const url of [`/api/workforce/planning/shifts/week?date=${monday}`, `/api/workforce/planning/shifts/board?from=${monday}&to=2026-12-13`, `/api/workforce/planning/shifts/statistics`])
    expect((await api(page, url)).status, url).toBe(200)
  const employee = (await api(page, '/api/workforce/employees?per_page=50')).json.data.find(e => e.employee_number === 'M7-A')
  const created = await api(page, '/api/workforce/planning/shifts', { method: 'POST', body: { 'employee_id': employee.id, date: '2026-12-08', 'start_at': '2026-12-08 09:00:00', 'end_at': '2026-12-08 12:00:00', timezone: 'Europe/Paris' } })

  expect(created.status, JSON.stringify(created.json)).toBe(201)
  expect(created.json.operation_id).toBeTruthy()

  const undo = await api(page, `/api/workforce/planning/operations/${created.json.operation_id}/undo`, { method: 'POST', body: {} })

  expect(undo.status, JSON.stringify(undo.json)).toBe(200)

  const redo = await api(page, `/api/workforce/planning/operations/${created.json.operation_id}/redo`, { method: 'POST', body: {} })

  expect(redo.status, JSON.stringify(redo.json)).toBe(200)
  expect((await api(page, `/api/workforce/planning/operations/${created.json.operation_id}/undo`, { method: 'POST', body: {} })).status).toBe(200)

  const preview = await api(page, '/api/workforce/planning/shifts/copy-week/preview', { method: 'POST', body: { 'from_week_start': '2026-03-09', 'to_week_start': '2026-12-07' } })

  expect(preview.status, JSON.stringify(preview.json)).toBe(200)

  // signature (SignatureController split), documents, employee record + access (EmployeeAccessController)
  // signature module not activated for company 106 : the screen explains it (SignatureController is covered by its PHP tests)
  await page.goto('/company/workforce/signature', { waitUntil: 'networkidle' })
  await expect(page).toHaveURL(/module-unavailable/)
  for (const [path, name] of [['/company/documents', 'A3-documents']]) {
    await page.goto(path, { waitUntil: 'networkidle' })
    await page.waitForTimeout(1200)
    await shot(page, name)
  }
  for (const url of [`/api/workforce/employees/${employee.id}/access`, `/api/workforce/employees/${employee.id}/file`])
    expect((await api(page, url)).status, url).toBe(200)

  // the invoice PDF : one renderer (InvoicePdfRenderer)
  const invoices = (await api(page, '/api/billing/invoices')).json
  const id = (invoices.data || invoices.invoices || invoices)[0]?.id

  expect(id, 'a finalized invoice of company 106').toBeTruthy()

  const pdf = await api(page, `/api/billing/invoices/${id}/pdf`, { raw: true })

  expect(pdf).toEqual({ status: 200, head: '%PDF-' })
  expect(problems).toEqual([])
  fs.writeFileSync(`${OUT}/recette-company.json`, JSON.stringify({ 'shift_operation': created.json.operation_id, invoice: id, pdf }, null, 2))
})

test('Platform : connexion et RBAC, boîte e-mail, facture PDF identique, supervision fin de contrat', async ({ page, browser }) => {
  test.setTimeout(5 * 60 * 1000)
  page.setDefaultTimeout(20000)

  const problems = watch(page)

  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await shot(page, 'B1-messagerie')
  expect((await api(page, '/api/platform/email/inbox?folder=inbox', { platform: true })).status).toBe(200)
  expect((await api(page, '/api/platform/email/inbox/unread-count', { platform: true })).status).toBe(200)

  const id = JSON.parse(fs.readFileSync(`${OUT}/recette-company.json`, 'utf8')).invoice

  expect(await api(page, `/api/platform/billing/invoices/${id}/pdf`, { platform: true, raw: true })).toEqual({ status: 200, head: '%PDF-' })
  await page.goto('/platform/companies/106/billing', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-testid="company-lifecycle"]')).toBeVisible()
  await shot(page, 'B2-fiche-entreprise')
  expect(problems).toEqual([])

  // RBAC : a read-only operator is refused the write routes and the PDF of billing
  const c = await browser.newContext()
  const v = await c.newPage()

  v.setDefaultTimeout(20000)
  await loginPlatform(v, 'viewer842@platform.test', PASSWORD)
  expect((await api(v, '/api/platform/companies/106/suspend', { method: 'PUT', platform: true })).status).toBe(403)
  expect((await api(v, `/api/platform/billing/invoices/${id}/pdf`, { platform: true, raw: true })).status).toBe(403)
  await c.close()
})
