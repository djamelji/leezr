// @ts-check
// Leezr 4.2 M8.2 (ADR-847) — Platform login with a LONG role key (R-M81-2) and the suspended Company mode (R-M81-1),
// browser recipe on the MARKED clone. Fixtures : scripts/audit842-m82-fixture.php (fictitious long-key operator),
// scripts/audit842-m8-fixture.php (reset | m81 | suspend) on fictitious company 106 only.
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M8.2 recipe only (AUDIT_PASSWORD_FILE)')

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m8/m82'
const OWNER = 'owner.at4f8@audit842.test'
const LONG_KEY = 'recette847_role_avec_une_cle_tres_longue'
const env = { ...process.env, DB_DATABASE: process.env.E2E_DB_DATABASE || 'leezr_audit842', CACHE_PREFIX: 'leezr_audit842-', REDIS_PREFIX: 'leezr_audit842-' }
const php = (...args) => execFileSync('php', args, { env }).toString()
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 }) }

/** every API answer ≥ 400 and every visible toast, to prove « no refused request, no raw notice » */
function watch(page) {
  const refused = []
  const toasts = []

  page.on('response', r => {
    const u = r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')
    if (r.status() >= 400 && u.startsWith('/api/') && !(r.status() === 429 && u.startsWith('/api/public/'))) refused.push(`${r.status()} ${r.request().method()} ${u}`)
  })

  return { refused, toasts, readToasts: async () => (await page.locator('.v-snackbar .v-snackbar__content, .Vue-Toastification__toast, [role="alert"].v-snackbar').allInnerTexts()).join(' | ') }
}

const apiStatus = (page, url, { method = 'GET', company = '106', platform = false } = {}) => page.evaluate(async ({ url, method, company, platform }) => {
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }
  if (!platform) headers['X-Company-Id'] = company
  const r = await fetch(url, { method, credentials: 'include', headers, body: method === 'GET' ? undefined : '{}' })

  return r.status
}, { url, method, company, platform })

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  php('scripts/audit842-m8-fixture.php', 'reset')
  php('scripts/audit842-m8-fixture.php', 'm81', process.env.AUDIT_PASSWORD_FILE)
  php('scripts/audit842-m82-fixture.php', process.env.AUDIT_PASSWORD_FILE)
})
test.afterAll(() => { php('scripts/audit842-m8-fixture.php', 'reset') })

test('Platform : rôle à clé longue, rôles existants, journal et permissions', async ({ browser }) => {
  test.setTimeout(6 * 60 * 1000)

  // 1 — login with a 40-character role key (was a 500)
  const c1 = await browser.newContext()
  const op = await c1.newPage()

  op.setDefaultTimeout(20000)

  const w = watch(op)

  await loginPlatform(op, 'op847@platform.test', PASSWORD)
  await op.goto('/platform/companies', { waitUntil: 'networkidle' })
  await expect(op.locator('body')).toContainText('Audit 842 Services')
  await shot(op, 'A1-connexion-cle-longue')

  // 4 — its permissions are still enforced (view_companies only)
  expect(await apiStatus(op, '/api/platform/companies/106/suspend', { method: 'PUT', platform: true })).toBe(403)
  expect(await apiStatus(op, '/api/platform/audit/platform', { platform: true })).toBe(403)
  await op.goto('/platform/access/logs', { waitUntil: 'networkidle' })
  await expect(op).toHaveURL(/\/platform\/403/)
  expect(w.refused.filter(r => !r.includes('/suspend') && !r.includes('/audit/platform')), 'no other refusal').toEqual([])
  await c1.close()

  // 2 — existing roles still log in (super admin, read-only viewer, short custom role)
  for (const email of ['admin@leezr.com', 'viewer842@platform.test', 'opa846@platform.test']) {
    const c = await browser.newContext()
    const p = await c.newPage()

    p.setDefaultTimeout(20000)
    await loginPlatform(p, email, PASSWORD)
    expect(p.url(), email).not.toMatch(/\/login$/)
    await c.close()
  }

  // 3 — the audit log : the long-key login is recorded as an admin WITH its full role, shown in the logs tab
  const c3 = await browser.newContext()
  const admin = await c3.newPage()

  admin.setDefaultTimeout(20000)
  await loginPlatform(admin, 'admin@leezr.com', PASSWORD)

  const logs = await admin.evaluate(async () => (await (await fetch('/api/platform/audit/platform?per_page=50', { credentials: 'include', headers: { Accept: 'application/json' } })).json()))
  const rows = logs.data || logs
  const mine = rows.find(r => r.action === 'platform_auth.login' && r.actor_role === 'recette847_role_avec_une_cle_tres_longue')

  expect(mine, 'login of the long-key operator audited').toBeTruthy()
  expect(mine.actor_type).toBe('admin')
  expect(mine.actor_role.length).toBe(LONG_KEY.length)
  await admin.goto('/platform/access/logs', { waitUntil: 'networkidle' })
  await expect(admin.locator(`[data-testid="log-actor-${mine.id}"]`)).toContainText(`admin · ${LONG_KEY}`)
  await admin.locator(`[data-testid="log-actor-${mine.id}"]`).scrollIntoViewIfNeeded()
  await shot(admin, 'A2-journal-role-complet')
  await c3.close()
})

test('Company suspendue : connexion, export, actualisation, reconnexion, rien d’autre', async ({ page, browser }) => {
  test.setTimeout(6 * 60 * 1000)
  page.setDefaultTimeout(20000)
  php('scripts/audit842-m8-fixture.php', 'reset')
  php('scripts/audit842-m8-fixture.php', 'suspend')

  const w = watch(page)

  // 5 — cold login lands on the recovery page ; no request to a refused feature, no raw notice
  await loginCompany(page, OWNER, PASSWORD)
  await expect(page).toHaveURL(/\/company\/billing\/closure/)
  await expect(page.locator('[data-test="closure-suspended"]')).toContainText('Votre entreprise est suspendue')

  const menu = await page.locator('.layout-vertical-nav .nav-link, .layout-vertical-nav .nav-group').allInnerTexts()

  expect(menu.map(m => m.trim()).filter(Boolean)).toEqual(['Données et fin de contrat', 'Support'])
  expect(w.refused, 'no refused request at login').toEqual([])
  await page.waitForTimeout(1500)
  expect(await page.locator('body').innerText()).not.toContain('This company is currently suspended')
  await shot(page, 'B1-connexion-societe-suspendue')

  // the export still works (password, archive really downloaded)
  await page.locator('[data-test="closure-export-request"]').click()
  await page.locator('[data-test="closure-export-password"] input').fill(PASSWORD)
  await page.locator('[data-test="closure-export-confirm"]').click()

  const chip = page.locator('[data-test^="closure-export-status-"]').first()

  await expect(chip).toHaveText(/Export (complet|partiel)/)

  const id = (await chip.getAttribute('data-test')).replace('closure-export-status-', '')
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator(`[data-test="closure-export-download-${id}"]`).click()])
  const zip = path.join(OUT, 'exemple-export-suspendu.zip')

  fs.copyFileSync(await dl.path(), zip)
  expect(JSON.parse(execFileSync('unzip', ['-p', zip, 'manifest.json']).toString()).company.id).toBe(106)

  // 7 — every other screen sends back here ; the server still refuses the features
  for (const url of ['/company/workforce/time/timesheets', '/dashboard', '/home', '/company/modules', '/company/people'])
    await page.goto(url, { waitUntil: 'networkidle' }).then(() => expect(page, url).toHaveURL(/\/company\/billing\/closure/))
  await page.goto('/company/support', { waitUntil: 'networkidle' })
  await expect(page).toHaveURL(/\/company\/support/)
  expect(w.refused, 'no refused request while navigating').toEqual([])

  const server = {}
  for (const url of ['/api/shipments', '/api/modules', '/api/nav', '/api/workforce/departments', '/api/notifications/unread-count'])
    server[url] = await apiStatus(page, url)
  expect(Object.values(server), JSON.stringify(server)).toEqual([403, 403, 403, 403, 403])

  // refresh, then a full re-login : same behaviour
  await page.goto('/company/billing/closure', { waitUntil: 'networkidle' })

  const before = w.refused.length

  await page.reload({ waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="closure-suspended"]')).toBeVisible()
  await page.context().clearCookies()
  await loginCompany(page, OWNER, PASSWORD)
  await expect(page).toHaveURL(/\/company\/billing\/closure/)

  // only the 5 calls fired on purpose above are refused
  expect(w.refused.slice(before).filter(r => !Object.keys(server).some(u => r.endsWith(u))), 'none after refresh / re-login').toEqual([])
  await page.waitForTimeout(1500)
  expect(await page.locator('body').innerText()).not.toContain('This company is currently suspended')
  await shot(page, 'B2-apres-reconnexion')

  // a member without billing rights : only what their role allows, no raw notice
  const c = await browser.newContext()
  const m = await c.newPage()

  m.setDefaultTimeout(20000)

  const wm = watch(m)

  await loginCompany(m, 'member842@audit842.test', PASSWORD)
  await expect(m).not.toHaveURL(/\/company\/billing/)
  await expect(m.locator('[data-testid="company-access-suspended"]')).toContainText('Entreprise suspendue')
  expect(wm.refused, 'member : no refused request').toEqual([])
  expect(await m.locator('body').innerText()).not.toContain('This company is currently suspended')
  await m.screenshot({ path: `${OUT}/apres-B3-membre-sans-droit.jpg`, type: 'jpeg', quality: 60 })
  await c.close()

  // reactivation : the recovery page resyncs the flag, the full menu comes back
  php('scripts/audit842-m8-fixture.php', 'reset')
  await page.goto('/company/billing/closure', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="closure-suspended"]')).toHaveCount(0)
  await expect(page.locator('.layout-vertical-nav')).toContainText('Planning')
  await shot(page, 'B4-reactivee-menu-complet')
})
