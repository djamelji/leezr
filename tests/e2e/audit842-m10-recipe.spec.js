// @ts-check
// Leezr 4.2 M10 (ADR-849) — measured public registration funnel, browser recipe on the MARKED clone.
// The public registration screen sends a random journey id (never a personal datum) ; a step is recorded once ; the
// Platform dashboard shows the outcomes and the explicit abandonment definition.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M10 recipe only (AUDIT_PASSWORD_FILE)')

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m10'
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 }) }

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles')
})

test('Inscription publique : un parcours, une étape enregistrée une fois, aucune donnée personnelle', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(20000)

  const sent = []

  page.on('request', r => {
    if (r.url().includes('/api/register/funnel-track') && r.method() === 'POST') sent.push(JSON.parse(r.postData() || '{}'))
  })
  await page.goto('/register', { waitUntil: 'networkidle' })
  await expect.poll(() => sent.length).toBeGreaterThan(0)

  const started = sent.find(s => s.step === 'started')

  expect(started['funnel_id']).toMatch(/^[0-9a-f-]{36}$/)
  expect(Object.keys(started).sort()).toEqual(['funnel_id', 'source', 'step']) // nothing else leaves the browser
  await shot(page, 'A1-inscription-ouverte')

  // a reload keeps the SAME journey (sessionStorage) and the step is not counted twice
  await page.reload({ waitUntil: 'networkidle' })
  await expect.poll(() => sent.filter(s => s.step === 'started').length).toBe(2)
  expect(new Set(sent.map(s => s['funnel_id'])).size).toBe(1)

  const rows = await page.evaluate(async id => (await fetch('/api/register/funnel-track', {
    method: 'POST', credentials: 'include',
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') },
    body: JSON.stringify({ 'funnel_id': id, 'step': 'started' }),
  })).json(), started['funnel_id'])

  expect(rows).toEqual({ ok: true, duplicate: true })
})

test('Platform : tableau de bord de l’entonnoir mesuré, définition explicite, droit requis', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await page.goto('/platform/dashboard/funnel', { waitUntil: 'networkidle' })
  await expect(page.getByTestId('funnel-definitions')).toContainText('sans aucune activité depuis 24 h')
  await expect(page.getByTestId('funnel-outcomes')).toContainText('première connexion')
  await expect(page.locator('body')).toContainText('Métier choisi')
  await shot(page, 'B1-tableau-de-bord-entonnoir')

  const data = await page.evaluate(async () => (await fetch('/api/platform/onboarding/funnel?days=30', { credentials: 'include', headers: { Accept: 'application/json' } })).json())

  expect(data['abandon_after_hours']).toBe(24)
  expect(data.steps.started).toBeGreaterThanOrEqual(1)
  for (const k of ['completed', 'activated', 'first_login', 'in_progress', 'abandoned']) expect(typeof data[k]).toBe('number')
})

// Non-regression of a transversal journey : inviting a person from Company (UI refactored in M6, ADR-843) — the
// historical audit journeys J3/J3b still target the pre-M6 screen ; this is the same intent on the real screen.
test('Company : ajouter une personne avec invitation, e-mail reçu', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(20000)

  const email = `collab.m10.${Date.now()}@audit842.test`

  await loginCompany(page, 'owner.at4f8@audit842.test', PASSWORD)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.locator('[data-test="people-add"]').click()
  await page.locator('[data-test="people-add-quick"]').click()
  await page.locator('[data-test="person-add-first-name"] input').fill('Invité')
  await page.locator('[data-test="person-add-last-name"] input').fill('Recette M10')
  await page.locator('[data-test="person-add-access-yes"] input').check()
  await page.locator('[data-test="person-add-email"] input').fill(email)
  await page.locator('[data-test="person-add-role"]').click()
  await page.locator('.v-overlay--active .v-list-item').first().click()
  await shot(page, 'C1-ajout-avec-invitation')
  await page.locator('[data-test="person-add-submit"]').click()
  await expect(page.locator('[data-test="person-add-drawer"]')).toHaveCount(0, { timeout: 20000 })

  const mailpit = process.env.MAILPIT_URL || 'http://127.0.0.1:8025'

  await expect.poll(async () => {
    const r = await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}&limit=1`)

    return r.ok ? (await r.json()).messages_count ?? 0 : 0
  }, { timeout: 20000 }).toBe(1)
})

