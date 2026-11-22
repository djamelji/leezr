// @ts-check
// Leezr 5.0 L3 (ADR-857) — Company workspace V22, browser recipe on the MARKED clone (profiles fixture only,
// order-independent). Proves : the hero states only real facts and offers server-filtered actions ; the recommended
// layout leads with the business of the active modules ; the driver gets his field home ; a new company gets the
// existing onboarding checklist and first operations instead of zeros ; a limited user and an other-JobDomain owner
// see nothing they may not read ; no request is ever refused (no 4xx/5xx on the API).
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers.js'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 workspace recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l3'
const shot = async (page, name) => { await page.waitForTimeout(700); await page.screenshot({ path: `${OUT}/recipe-${name}.jpg`, type: 'jpeg', quality: 62, fullPage: true }) }
const hero = page => page.locator('[data-test="workspace-hero"]')

/** every API answer of the page : a refused or failing request is a defect */
function watchApi(page) {
  const bad = []

  page.on('response', r => {
    if (r.url().includes('/api/') && r.status() >= 400 && !r.url().includes('/api/login')) bad.push(`${r.status()} ${new URL(r.url()).pathname}`)
  })
  page.on('pageerror', e => bad.push(`js ${e.message}`))

  return bad
}

const layout = page => page.evaluate(async () => {
  const cid = JSON.parse(decodeURIComponent((document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/) || [])[1] || 'null'))
  const r = await fetch('/api/dashboard/layout', { credentials: 'include', headers: { Accept: 'application/json', ...(cid ? { 'X-Company-Id': String(cid) } : {}) } })

  return (await r.json()).layout || []
})

async function open(page, email, path = '/dashboard') {
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, email, PASSWORD)
  await page.goto(path, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1800)
}

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles')
})

test('Dirigeant Logistique : situation réelle, action prioritaire, disposition menée par le métier', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  const bad = watchApi(page)

  await open(page, 'owner@leezr.test')
  await expect(hero(page)).toBeVisible()

  // the sentence quotes the server priorities (the owner of company 1 has some) — never a « all is fine » claim
  await expect(hero(page)).toContainText(/vous attend/)
  await expect(hero(page)).not.toContainText('Aucune action ne vous attend')

  // recommended layout : the day's shipment strip leads (Logistics is active), Workforce follows
  await page.locator('[data-test="home-customize"]').click()
  await page.locator('[data-test="home-recommended"]').click()
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/api/dashboard/layout') && r.request().method() === 'PUT' && r.ok()),
    page.locator('[data-test="home-reset-confirm"]').click(),
  ])
  await page.locator('[data-test="home-customize-done"]').click()

  const tiles = await layout(page)
  const firstRow = tiles.filter(t => t.y === 0).map(t => t.key)

  expect(firstRow.every(k => k.startsWith('shipments.') || k.startsWith('deliveries.'))).toBe(true)
  expect(tiles.find(t => t.key === 'shipments.overview').y).toBeLessThan(tiles.find(t => t.key === 'workforce.headcount').y)

  // a full first row : an incomplete strip shares the 12 columns
  expect(tiles.filter(t => t.y === 0).reduce((n, t) => n + t.w, 0)).toBe(12)

  // hero actions are never repeated as tiles : each served action exists ONCE on the page (ADR-775 contract)
  const heroTests = await hero(page).locator('a.v-btn').evaluateAll(els => els.map(e => e.getAttribute('data-test')))

  expect(heroTests.length).toBeGreaterThan(0)
  for (const k of heroTests.filter(x => x.startsWith('home-action-'))) await expect(page.locator(`[data-test="${k}"]`)).toHaveCount(1)
  await shot(page, 'A1-dirigeant')

  // the primary action opens the treatment itself
  await hero(page).locator('a.v-btn').first().click()
  await page.waitForLoadState('networkidle')
  expect(new URL(page.url()).pathname).not.toBe('/dashboard')
  expect(bad).toEqual([])
})

test('Chauffeur : accueil terrain, ses livraisons, aucun indicateur de gestion', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)

  const bad = watchApi(page)
  const asked = []

  page.on('request', r => { if (r.url().includes('/api/dashboard/priorities') || r.url().includes('/api/dashboard/widgets/catalog')) asked.push(new URL(r.url()).pathname) })
  await open(page, 'bob@leezr.test', '/home')
  await expect(hero(page)).toContainText('Bonne tournée')
  await expect(hero(page)).toContainText(/livraisons? vous (est|sont) assignée/)
  await expect(page.locator('[data-test="home-priorities"]')).toHaveCount(0)
  await shot(page, 'B1-chauffeur')
  await hero(page).locator('[data-test="workspace-hero-action-my-deliveries"]').click()
  await expect(page).toHaveURL(/\/company\/my-deliveries/)
  expect(asked.filter(p => p.endsWith('/priorities'))).toEqual([])
  expect(bad).toEqual([])
})

test('Nouvelle société : la checklist existante et les premières opérations, pas de mur de zéros', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)

  const bad = watchApi(page)

  await open(page, 'pending@leezr.test')
  await expect(hero(page)).toContainText('Mise en service')
  await expect(hero(page)).toContainText('mise en service') // ring label
  await expect(page.locator('[data-test="home-first-steps"]')).toBeVisible()
  await expect(page.locator('.dashboard-tile')).toHaveCount(0)
  await expect(page.getByText('Bienvenue sur Leezr')).toBeVisible() // the ONE onboarding checklist (ADR-383)
  await shot(page, 'C1-nouvelle-societe')

  // the indicators remain reachable on demand
  await page.locator('[data-test="home-show-indicators"]').click()
  await expect(page.locator('.dashboard-tile').first()).toBeVisible()
  expect(bad).toEqual([])
})

test('Autre métier, utilisateur limité, paie : rien d’inventé, rien d’interdit', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  const bad = watchApi(page)

  await open(page, 'ownerb@leezr.test')
  await expect(hero(page)).toBeVisible()
  await expect(hero(page)).not.toContainText(/expédition|livraison/i)
  expect((await layout(page)).map(t => t.key).filter(k => k.startsWith('shipments.'))).toEqual([])
  await shot(page, 'D1-autre-metier')

  for (const email of ['consultation@leezr.test', 'pay@leezr.test']) {
    await page.context().clearCookies()
    await open(page, email)
    await expect(hero(page)).toBeVisible()
    await expect(page.locator('[data-test="home-overview-empty"], .dashboard-tile').first()).toBeVisible()
  }
  await shot(page, 'D2-paie')
  expect(bad).toEqual([])
})

// ADR-776 / ADR-775 contract kept by the V22 home (read-only) : every served priority is listed, every served home
// action is reachable exactly ONCE (hero or tile), nothing is refused — for each management profile of the clone.
for (const email of ['owner@leezr.test', 'admin@leezr.test', 'rh-nopay@leezr.test', 'manager@leezr.test', 'pay@leezr.test', 'consultation@leezr.test']) {
  test(`Contrat accueil — ${email}`, async ({ page }) => {
    test.setTimeout(3 * 60 * 1000)

    const bad = watchApi(page)

    await open(page, email)
    if (new URL(page.url()).pathname !== '/dashboard') return

    const served = await page.evaluate(async () => {
      const cid = JSON.parse(decodeURIComponent((document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/) || [])[1] || 'null'))
      const h = { Accept: 'application/json', ...(cid ? { 'X-Company-Id': String(cid) } : {}) }
      const pr = await (await fetch('/api/dashboard/priorities', { credentials: 'include', headers: h })).json()
      const nav = await (await fetch('/api/nav', { credentials: 'include', headers: h })).json()

      return { priorities: pr.priorities.map(p => p.key), actions: nav.actions.filter(a => a.home && a.to).map(a => a.key) }
    })

    await expect(page.locator('[data-test^="home-priority-"]')).toHaveCount(served.priorities.length)
    for (const k of served.actions) await expect(page.locator(`[data-test="home-action-${k}"]`)).toHaveCount(1)
    expect(bad).toEqual([])
  })
}
