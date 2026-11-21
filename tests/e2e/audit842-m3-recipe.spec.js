// @ts-check
// Leezr 4.2 M3 (ADR-839) — browser recipe with REAL navigation on the marked clone (global-setup, ADR-831).
// Fixtures : scripts/audit842-m2-fixture.php, scripts/audit842-m3-fixture.php (fictitious companies 105 / 106 only).
// Every page : JS errors and unexpected API refusals are collected ; only the refusals the scenario provokes are allowed.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M3 recipe only (AUDIT_PASSWORD_FILE)')

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m3'
const shot = async (page, name) => { await page.waitForTimeout(500); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 }) }

function watch(page) {
  const problems = []

  page.on('pageerror', e => problems.push(`js ${e.message}`))
  page.on('response', r => {
    // 429 on the public boot endpoints = the recipe reloads dozens of pages per minute (rate limit, not a permission refusal)
    if (r.status() >= 400 && r.url().includes('/api/') && !r.url().includes('/realtime/') && !(r.status() === 429 && r.url().includes('/api/public/')))
      problems.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')}`)
  })

  return problems
}

const api = (page, method, url, companyId = null, body = null) => page.evaluate(async ([m, u, c, b]) => {
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }
  if (c) headers['X-Company-Id'] = String(c)
  const r = await fetch(u, { method: m, credentials: 'include', headers, body: b ? JSON.stringify(b) : undefined })

  return { status: r.status, text: await r.text() }
}, [method, url, companyId, body])

const open = async (page, path) => { await page.goto(path, { waitUntil: 'networkidle' }); await page.waitForTimeout(800) }

test.beforeEach(({ page }) => { page.setDefaultTimeout(15000); fs.mkdirSync(OUT, { recursive: true }) })

// ── Platform ──────────────────────────────────────────────────────────────────────────────────────────────

test('M3-1 super-admin : tous les onglets, aucun refus', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  const problems = watch(page)

  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  for (const tab of ['overview', 'activity', 'funnel']) await open(page, `/platform/dashboard/${tab}`)
  await open(page, '/platform/billing/overview')
  await expect(page.getByRole('tab', { name: /Coupons/ })).toBeVisible()
  for (const tab of ['coupons', 'tariff-review']) await open(page, `/platform/billing/${tab}`)
  await open(page, '/platform/companies/105')
  await expect(page.getByTestId('platform-record-forbidden')).toHaveCount(0)
  expect(problems.filter(p => !p.startsWith('404 GET /api/platform/modules/')), 'no JS error, no API refusal').toEqual([])
})

test('M3-2 profil Finance : onglets interdits masqués, page interdite explicite, aucun 403 évitable', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)

  const problems = watch(page)

  await loginPlatform(page, 'finance-reader@leezr.com', PASSWORD)
  await open(page, '/platform/dashboard/overview')
  await expect(page.getByRole('tab', { name: /Activité/ })).toHaveCount(0)
  await expect(page.getByRole('tab', { name: /Funnel/ })).toHaveCount(0)
  await shot(page, '2a-finance-tableau-de-bord')
  for (const tab of ['overview', 'subscriptions', 'invoices', 'payments', 'dunning', 'scheduled-debits', 'credit-notes', 'wallets', 'forensics', 'governance', 'ledger', 'recovery', 'reconciliation'])
    await open(page, `/platform/billing/${tab}`)
  await expect(page.getByRole('tab', { name: /Coupons/ })).toHaveCount(0)

  // a tab reached by its address : explicit state, no call
  await open(page, '/platform/dashboard/activity')
  await expect(page.getByTestId('platform-tab-forbidden')).toBeVisible()
  await shot(page, '2b-finance-onglet-reserve')

  // a page outside the profile : explicit page (no silent bounce), back to the previous page works
  await open(page, '/platform/companies')
  await expect(page).toHaveURL(/\/platform\/403/)
  await expect(page.getByTestId('platform-forbidden')).toContainText('Votre profil Platform ne donne pas accès à cette page')
  await shot(page, '2c-finance-page-interdite')
  await page.getByTestId('platform-forbidden-back').click()
  await expect(page).toHaveURL(/\/platform\/dashboard\/activity/)
  expect(problems, 'no avoidable 403, no JS error').toEqual([])
})

test('M3-3 création seule (create_companies) : création visible, fiche d’une autre société refusée sans données', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)

  const problems = watch(page)

  await loginPlatform(page, 'creator842@platform.test', PASSWORD)
  await open(page, '/platform/companies/create')
  await expect(page.locator('form, .v-stepper, .v-window').first()).toBeVisible()
  await expect(page.getByTestId('platform-forbidden')).toHaveCount(0)
  await open(page, '/platform/companies/1')
  await expect(page.getByTestId('platform-record-forbidden')).toBeVisible()
  await expect(page.locator('body')).not.toContainText('Leezr Logistics') // no data of the refused record
  await shot(page, '3-createur-fiche-refusee')

  // direct API without the right : refused by the server
  expect((await api(page, 'PUT', '/api/platform/companies/1/plan', null, { plan_key: 'starter' })).status).toBe(403)
  expect((await api(page, 'GET', '/api/platform/companies')).status).toBe(403)
  expect(problems.filter(p => !/^403 (GET|PUT) \/api\/platform\/companies(\/1|\/1\/plan)?$/.test(p))).toEqual([])
})

// ── Company ───────────────────────────────────────────────────────────────────────────────────────────────

test('M3-4/8/9/10 propriétaire : module inactif, hors offre, dépendance manquante', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  const problems = watch(page)

  await loginCompany(page, 'camille.recette834@fictif.test', PASSWORD)

  // B — compatible but not activated : explicit page (no silent redirect), activation offered to the owner
  await open(page, '/company/workforce/planning')
  await expect(page).toHaveURL(/module-unavailable/)
  await expect(page.getByTestId('module-unavailable-inactive')).toContainText("n'est pas activé")
  await expect(page.getByTestId('module-unavailable-activate')).toBeVisible()
  await shot(page, '4a-proprietaire-module-inactif')

  // C — not in the plan : « available from Business », plans offered (billing.manage) — nothing activated
  await open(page, '/company/workforce/payroll')
  await expect(page.getByTestId('module-unavailable-plan')).toContainText('Business')
  await expect(page.getByTestId('module-unavailable-plans')).toBeVisible()
  await shot(page, '4b-proprietaire-hors-offre')

  // dependency missing : contracts rely on Documents (not active) → listed as missing
  await open(page, '/company/workforce/contract/templates')

  const dep = page.getByTestId('module-unavailable-inactive')

  await expect(dep).toContainText('Il s\'appuie sur')
  await expect(dep.locator('.v-chip').filter({ hasText: /Documents/ })).toBeVisible()
  await shot(page, '4c-proprietaire-dependance')

  // « Activate in Modules » opens the module details, does NOT activate anything
  await open(page, '/company/workforce/planning')
  await page.getByTestId('module-unavailable-activate').click()
  await expect(page).toHaveURL(/\/company\/modules\?module=workforce_planning/)
  await page.waitForTimeout(1500)
  await shot(page, '4d-proprietaire-page-modules')

  const mods = JSON.parse((await api(page, 'GET', '/api/modules', 105)).text).modules

  expect(mods.find(m => m.key === 'workforce_planning').is_active, 'never activated implicitly').toBe(false)
  expect(mods.find(m => m.key === 'workforce_payroll').is_active).toBe(false)

  // ADR-808 : in this company created from Platform, the delivery rights of the driver role wait for the owner's
  // confirmation ; the owner confirms them (grouped review, same engine, audited) before the driver scenario.
  const review = JSON.parse((await api(page, 'GET', '/api/company/pending-access', 105)).text)

  expect((await api(page, 'POST', '/api/company/pending-access/confirm', 105, { fingerprint: review.fingerprint })).status).toBe(200)
  expect(problems).toEqual([])
})

test('M3-5 manager : module inactif expliqué sans action d’administration, API refusée', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)

  const problems = watch(page)

  await loginCompany(page, 'manager843@audit842.test', PASSWORD)
  await open(page, '/company/workforce/planning')
  await expect(page.getByTestId('module-unavailable-inactive')).toBeVisible()
  await expect(page.getByTestId('module-unavailable-activate')).toHaveCount(0)
  await expect(page.getByTestId('module-unavailable-inactive-hint')).toContainText('demandez à un administrateur')
  await shot(page, '5-manager-module-inactif')
  expect((await api(page, 'PUT', '/api/modules/workforce_planning/enable', 105)).status, 'activation refused').toBe(403)
  expect(problems.filter(p => p !== '403 PUT /api/modules/workforce_planning/enable')).toEqual([])
})

test('M3-6/11/12/13 chauffeur au moindre privilège : annuaire refusé (page et API), livraisons accessibles, autre société refusée', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)

  const problems = watch(page)

  await loginCompany(page, 'driver843@audit842.test', PASSWORD)
  await open(page, '/company/my-deliveries')
  await expect(page.getByTestId('company-forbidden')).toHaveCount(0)
  await shot(page, '6a-chauffeur-livraisons')

  await open(page, '/company/people')
  await expect(page.getByTestId('company-forbidden')).toBeVisible()
  await expect(page.locator('body')).not.toContainText('manager843@audit842.test')
  await page.waitForTimeout(6000) // the former page bounced after 5 s : the explanation now stays
  await expect(page.getByTestId('company-forbidden')).toBeVisible()
  await shot(page, '6b-chauffeur-annuaire-refuse')

  const members = await api(page, 'GET', '/api/company/members', 105)

  expect(members.status, 'directory API refused').toBe(403)
  expect(members.text).not.toContain('@audit842.test')


  // another company : refused, nothing leaks
  const other = await api(page, 'GET', '/api/company/members', 106)

  expect(other.status).toBe(403)
  expect(other.text).not.toContain('pay843')
  expect(problems.filter(p => !/^403 GET \/api\/company\/members$/.test(p))).toEqual([])
})

test('M3-7 responsable paie : paie accessible, facturation réservée', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)

  const problems = watch(page)

  await loginCompany(page, 'pay843@audit842.test', PASSWORD)
  await open(page, '/company/workforce/payroll')
  await expect(page).not.toHaveURL(/module-unavailable|403/)
  await shot(page, '7a-paie-accessible')
  await open(page, '/company/billing/overview')
  await expect(page.getByTestId('company-forbidden')).toBeVisible()
  await shot(page, '7b-paie-facturation-reservee')
  expect((await api(page, 'GET', '/api/billing/overview', 106)).status).toBe(403)
  expect(problems.filter(p => p !== '403 GET /api/billing/overview')).toEqual([])
})
