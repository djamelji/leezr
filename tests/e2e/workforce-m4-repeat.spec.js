// Refonte Smart Planning — M4 (ADR-727) : répétition multi-semaines, depuis la VRAIE interface.
// A (répéter 4 semaines nominative) + F (annuler la répétition) + G (mobile). B/C/D/E : PHPUnit (concurrence,
// contrat expiré, charge) — la répétition réutilise le même parcours preview/apply.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })
const API = '/api/workforce/planning'
const tpls = new Set(); const weeks = new Set(); const shifts = new Set()
const FIRST = '2027-04-05'

test.afterEach(async ({ page }) => {
  for (const id of shifts) await apiRequest(page, { method: 'DELETE', path: `${API}/shifts/${id}` })
  for (const id of weeks) await apiRequest(page, { method: 'DELETE', path: `${API}/week-templates/${id}` })
  for (const id of tpls) await apiRequest(page, { method: 'DELETE', path: `${API}/templates/${id}` })
  shifts.clear(); weeks.clear(); tpls.clear()
})

async function templatesTab(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'domcontentloaded' })
  const tab = page.locator('[data-test="plan-view-templates"]')
  await tab.waitFor({ state: 'visible', timeout: 30000 }); await tab.click(); await page.waitForTimeout(500)
}
async function makeTemplate(page, start, end) {
  const name = 'AAA Rep ' + Date.now() + '-' + Math.floor(Math.random() * 1000)
  await page.locator('[data-test="template-new"]').click()
  await page.locator('[data-test="template-name"] input').fill(name)
  await page.locator('[data-test="template-start"] input').fill(start)
  await page.locator('[data-test="template-end"] input').fill(end)
  const [r] = await Promise.all([
    page.waitForResponse(x => /\/planning\/templates$/.test(x.url()) && x.request().method() === 'POST'),
    page.locator('[data-test="template-submit"]').click(),
  ])
  const j = await r.json(); tpls.add(j.id)
  return { id: j.id, name }
}
async function pickSelect(page, testid, text) {
  await page.locator(`[data-test="${testid}"]`).locator('.v-field, input').first().click()
  const opts = page.locator('.v-overlay-container .v-list-item, .v-menu .v-list-item')
  await opts.first().waitFor({ state: 'visible', timeout: 8000 })
  if (text) await opts.filter({ hasText: text }).first().click()
  else await opts.first().click()
  await page.waitForTimeout(150)
}
async function makeWeek(page, tplName) {
  await page.locator('[data-test="week-library-open"]').click(); await page.waitForTimeout(400)
  await page.locator('[data-test="week-new"]').click()
  await page.locator('[data-test="week-name"] input').fill('Rep ' + Date.now())
  await page.locator('[data-test="week-add-line"]').click()
  await pickSelect(page, 'week-line-template', tplName)
  await pickSelect(page, 'week-line-employee', null)
  const [wr] = await Promise.all([
    page.waitForResponse(x => /\/planning\/week-templates$/.test(x.url()) && x.request().method() === 'POST'),
    page.locator('[data-test="week-save"]').click(),
  ])
  const wt = await wr.json(); weeks.add(wt.id)
  return wt
}

test('M4 scénario A+F — répéter 4 semaines, vérifier les brouillons, puis annuler', async ({ page }) => {
  test.setTimeout(220000)
  await loginCompany(page)
  await templatesTab(page)
  const tpl = await makeTemplate(page, '03:00', '04:00')
  const wt = await makeWeek(page, tpl.name)

  await page.locator(`[data-test="week-apply-${wt.id}"]`).click()
  await expect(page.locator('[data-test="week-repeat-toggle"]')).toBeVisible()
  await page.locator('[data-test="week-repeat-toggle"]').click() // mode répétition
  await expect(page.locator('[data-test="week-repeat-preview-btn"]')).toBeVisible()
  await page.locator('[data-test="week-target-date"] input').fill(FIRST)
  // horizon par défaut = 4
  const [pv] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/repeat-preview`).test(x.url())),
    page.locator('[data-test="week-repeat-preview-btn"]').click(),
  ])
  const prev = await pv.json()
  expect(prev.global.weeks).toBe(4)
  await expect(page.locator('[data-test="week-repeat-summary"]')).toBeVisible()
  await expect(page.locator('[data-test="week-repeat-week"]')).toHaveCount(4) // 4 semaines navigables

  const [ar] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/repeat-apply`).test(x.url())),
    page.locator('[data-test="week-repeat-apply-btn"]').click(),
  ])
  const res = await ar.json()
  ;(res.created || []).forEach(id => shifts.add(id))
  test.skip(!(res.created_count > 0), 'Salarié non éligible (base DEV).')
  await expect(page.locator('[data-test="week-repeat-result"]')).toBeVisible()
  // Brouillons créés sur plusieurs semaines (1 ligne × 4 semaines).
  expect(res.created_count).toBe(4)
  expect(res.weeks.length).toBe(4)
  expect(res.operation_id).toBeTruthy()

  // F — Annuler la répétition (opération unique) → tous les shifts retirés.
  const undo = await apiRequest(page, { method: 'POST', path: `${API}/operations/${res.operation_id}/undo` })
  expect(undo.status).toBe(200)
  for (const id of res.created) {
    const g = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${id}` })
    expect((g.json?.data ?? g.json ?? [])).toHaveLength(0)
  }
  shifts.clear() // supprimés par l'undo
})

test('M4 scénario G — mobile : répéter, horizon, prévisualiser (scroll)', async ({ page }) => {
  test.setTimeout(160000)
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page)
  await templatesTab(page)
  const tpl = await makeTemplate(page, '02:00', '03:00')
  const wt = await makeWeek(page, tpl.name)

  await page.locator(`[data-test="week-apply-${wt.id}"]`).click()
  await page.locator('[data-test="week-repeat-toggle"]').click()
  await page.locator('[data-test="week-target-date"] input').fill(FIRST)
  await pickSelect(page, 'week-repeat-horizon', '2 ') // 2 semaines
  const [pv] = await Promise.all([
    page.waitForResponse(x => new RegExp(`/week-templates/${wt.id}/repeat-preview`).test(x.url())),
    page.locator('[data-test="week-repeat-preview-btn"]').click(),
  ])
  expect((await pv.json()).global.weeks).toBe(2)
  await expect(page.locator('[data-test="week-repeat-summary"]')).toBeVisible()
  const apply = page.locator('[data-test="week-repeat-apply-btn"]')
  await apply.scrollIntoViewIfNeeded()
  await expect(apply).toBeVisible()
})
