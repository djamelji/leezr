// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

/**
 * PRODUCT-UX-3 / P3-A — recette DEV : expérience personne unifiée.
 * Carte « Accès & compte » sur la fiche + badges Salarié/Compte sur Members.
 * Lecture seule + navigation (aucune donnée altérée).
 */
const SHOTS = process.env.P3A_SHOTS || '/tmp/p3a-shots'
fs.mkdirSync(SHOTS, { recursive: true })
async function shot(page, name, full = true) { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full }).catch(() => {}) }

test('P3-A DEV — fiche access card + members salarié badges', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(20000)
  await loginCompany(page)

  // Pick an employee id from the API.
  const list = await apiRequest(page, { method: 'GET', path: '/api/workforce/employees?per_page=1' })
  const emp = (list.json?.data || list.json || [])[0]
  const empId = emp?.id
  console.log('employee id:', empId)

  // Fiche overview shows the unified "Accès & compte" card.
  await page.goto(`/company/workforce/employees/${empId}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(3000)
  await expect(page.getByText(/accès & compte/i).first()).toBeVisible()
  await shot(page, '01-fiche-overview-access')

  // Access status API: no salary, has a state.
  const access = await apiRequest(page, { method: 'GET', path: `/api/workforce/employees/${empId}/access` })
  console.log('access state:', access.json?.state, '| no _cents:', !JSON.stringify(access.json).includes('_cents'))

  // Members page: Salarié / Compte seul badges.
  await page.goto('/company/members', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  const salarie = await page.getByText(/^Salarié$/).count()
  const compteSeul = await page.getByText(/compte seul/i).count()
  console.log('members Salarié badges:', salarie, '| Compte seul badges:', compteSeul)
  await shot(page, '02-members-badges')

  // Members API carries is_employee.
  const members = await apiRequest(page, { method: 'GET', path: '/api/company/members' })
  const hasFlag = JSON.stringify(members.json).includes('is_employee')
  console.log('members payload has is_employee:', hasFlag)

  // Responsive fiche.
  await page.goto(`/company/workforce/employees/${empId}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2000)
  for (const [w, h, name] of [[1366, 768, '03-laptop'], [390, 844, '04-mobile']]) {
    await page.setViewportSize({ width: w, height: h })
    await page.waitForTimeout(700)
    await shot(page, name)
  }
})

test('P3-A DEV — dark fiche', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark' })
  const page = await ctx.newPage()
  page.setDefaultTimeout(20000)
  await loginCompany(page)
  const list = await apiRequest(page, { method: 'GET', path: '/api/workforce/employees?per_page=1' })
  const empId = ((list.json?.data || list.json || [])[0] || {}).id
  await page.goto(`/company/workforce/employees/${empId}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(3000)
  await shot(page, '05-dark-fiche')
  await ctx.close()
})
