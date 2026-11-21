// @ts-check
// Leezr 4.2 M6 (ADR-843) — Company workspace, browser recipe with REAL clicks on the MARKED clone (ADR-831).
// Fictitious companies only : 105 « Recette 834 Transports Fictifs » (Logistique) and 106 « Audit 842 Services » (Beta Services).
// Fixtures : audit842-m3-fixture.php, audit842-m6-fixture.php. Every page : JS errors and unexpected API refusals collected.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M6 recipe only (AUDIT_PASSWORD_FILE)')
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m6'
const RUN = Date.now().toString(36).slice(-5)
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 }) }

function watch(page) {
  const problems = []
  page.on('pageerror', e => problems.push(`js ${e.message}`))
  page.on('console', m => { if (m.type() === 'warning' && /Failed to resolve (directive|component)/.test(m.text())) problems.push(`vue ${m.text()}`) })
  page.on('response', r => {
    if (r.status() >= 400 && r.url().includes('/api/') && !r.url().includes('/realtime/') && !(r.status() === 429 && r.url().includes('/api/public/')))
      problems.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')}`)
  })

  return problems
}
const open = async (page, path) => { await page.goto(path, { waitUntil: 'networkidle' }); await page.waitForTimeout(900) }
const noHorizontalScroll = async page => expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)

test.beforeEach(({ page }) => { page.setDefaultTimeout(15000); fs.mkdirSync(OUT, { recursive: true }) })

test('Propriétaire (Logistique) — accueil, Personnes : une action « Ajouter » à deux choix, ajout rapide, vocabulaire d’accès', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  const problems = watch(page)
  await loginCompany(page, 'camille.recette834@fictif.test', PASSWORD)
  await open(page, '/home')
  await shot(page, 'A1-proprietaire-accueil')

  // menu : « Équipe › Personnes » (was « Équipe › Équipe »)
  await page.locator('.nav-group', { hasText: 'Équipe' }).first().click()
  await page.getByRole('link', { name: 'Personnes' }).first().click()
  await page.waitForURL(/\/company\/people/)
  await page.waitForTimeout(1200)
  await expect(page.locator('[data-test="people-hire"]')).toHaveCount(0) // no second competing button
  await page.locator('[data-test="people-add"]').click()
  await expect(page.locator('[data-test="people-hire"]')).toContainText('Embauche complète')
  await expect(page.locator('[data-test="people-add-quick"]')).toContainText('Ajout rapide')
  await shot(page, 'A2-ajouter-deux-choix')

  // quick add : a record without access
  await page.locator('[data-test="people-add-quick"]').click()
  await page.locator('[data-test="person-add-first-name"] input').fill(`Rec M6 ${RUN}`)
  await page.locator('[data-test="person-add-last-name"] input').fill('Ajout')
  const no = page.locator('[data-test="person-add-access-no"] input')
  if (await no.count()) await no.check()
  await page.locator('[data-test="person-add-submit"]').click()
  await page.waitForTimeout(2000)
  await expect(page.getByText(`Rec M6 ${RUN} Ajout`).first()).toBeVisible()
  const body = await page.locator('.layout-page-content').innerText()
  for (const old of ['Compte connecté', 'Compte activé']) expect(body, `one access vocabulary (no « ${old} »)`).not.toContain(old)
  await shot(page, 'A3-personnes-apres-ajout')

  // an old onboarding link opened while signed in : the real reason
  await open(page, '/onboarding')
  await expect(page.locator('[data-test="onboarding-reserved"]')).toContainText('réservé à la personne invitée')
  expect(problems.filter(p => p !== '401 GET /api/public/onboarding')).toEqual([])
})

test('Dispatcher → Chauffeur (Logistique) — créer, affecter, planifier ; le chauffeur la livre depuis son téléphone', async ({ page, browser }) => {
  test.setTimeout(6 * 60 * 1000)
  const problems = watch(page)
  await loginCompany(page, 'dispatch843@audit842.test', PASSWORD)
  await open(page, '/company/shipments')
  await page.getByRole('link', { name: /Nouvelle expédition|Créer/ }).first().click()
  await page.waitForURL(/shipments\/create/)
  await page.getByLabel(/Adresse d.origine/i).fill(`Dépôt M6 ${RUN}`)
  await page.getByLabel(/Adresse de destination/i).fill(`Client M6 ${RUN}`)
  await page.getByLabel(/Notes/i).fill(`Recette M6 ${RUN}`)
  await page.locator('form').getByRole('button', { name: /Créer/ }).first().click()
  await page.waitForURL(/\/company\/shipments\/\d+/)
  await page.waitForTimeout(1200)
  await page.locator('[data-test="shipment-assignee"]').click()
  await page.locator('.v-overlay--active .v-list-item', { hasText: 'Dany' }).first().click()
  await page.locator('[data-test="shipment-assign"]').click()
  await page.waitForTimeout(1200)
  await expect(page.locator('[data-test="shipment-driver"]')).toContainText('Dany')
  if (await page.locator('[data-test="shipment-to-planned"]').count()) await page.locator('[data-test="shipment-to-planned"]').click()
  else if (await page.locator('[data-test="shipment-plan"]').count()) {
    await page.locator('[data-test="shipment-plan"]').click()
    await page.locator('[data-test="shipment-plan-date"] input').fill(new Date(Date.now() + 3600e3).toISOString().slice(0, 16))
    await page.locator('[data-test="shipment-plan-save"]').click()
  }
  await page.waitForTimeout(1500)
  await shot(page, 'B1-dispatcher-expedition-affectee')
  // search in the list
  await open(page, '/company/shipments')
  await page.getByPlaceholder(/Rechercher/).first().fill(`M6 ${RUN}`)
  await page.waitForTimeout(1500)
  await expect(page.locator('[data-test="shipments-table"] tbody tr')).toHaveCount(1)
  expect(problems).toEqual([])

  // driver, on a phone
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })
  const phone = await ctx.newPage()
  phone.setDefaultTimeout(15000)
  const pp = watch(phone)
  await loginCompany(phone, 'driver843@audit842.test', PASSWORD)
  await open(phone, '/company/my-deliveries')
  const card = phone.locator('[data-test^="delivery-card-"]', { hasText: `Client M6 ${RUN}` }).first()
  await expect(card).toBeVisible()
  await noHorizontalScroll(phone)
  await phone.screenshot({ path: `${OUT}/apres-B2-chauffeur-mobile.jpg`, type: 'jpeg', quality: 60 })
  // start, then deliver — without changing filter (the screen follows the delivery to « En cours »)
  await card.locator('[data-test^="delivery-advance-"]').click()
  await phone.waitForTimeout(1500)
  await expect(phone.locator('.v-alert').first()).toContainText('En transit')
  const started = phone.locator('[data-test^="delivery-card-"]', { hasText: `Client M6 ${RUN}` }).first()
  await expect(started).toBeVisible()
  await started.locator('[data-test^="delivery-advance-"]').click()
  await phone.waitForTimeout(1500)
  await expect(phone.locator('.v-alert').first()).toContainText('Livrée')
  await phone.screenshot({ path: `${OUT}/apres-B3-chauffeur-livree.jpg`, type: 'jpeg', quality: 60 })
  await open(phone, '/company/people')
  await expect(phone.getByTestId('company-forbidden')).toBeVisible() // no directory needed to work
  expect(pp).toEqual([])
  await ctx.close()
})

test('Manager, Salarié, Membre limité — chacun voit ses actions, rien de financier', async ({ browser }) => {
  test.setTimeout(5 * 60 * 1000)
  for (const [email, label] of [['manager843@audit842.test', 'manager'], ['salarie843@audit842.test', 'salarie'], ['member842@audit842.test', 'limite']]) {
    const ctx = await browser.newContext()
    const page = await ctx.newPage()
    page.setDefaultTimeout(15000)
    const problems = watch(page)
    await loginCompany(page, email, PASSWORD)
    await open(page, '/home')
    await shot(page, `C-${label}-accueil`)
    const calls = []
    page.on('request', r => { if (/\/api\/(billing|company\/plan)/.test(r.url())) calls.push(r.url()) })
    await open(page, '/home')
    expect(calls, `${label} : no billing call`).toEqual([])
    await expect(page.locator('[data-test="people-add"]')).toHaveCount(0)
    expect(problems, label).toEqual([])
    await ctx.close()
  }
})

test('Responsable paie (Beta Services) et propriétaire Beta Services — second métier, desktop et mobile', async ({ browser }) => {
  test.setTimeout(5 * 60 * 1000)
  for (const [email, path, label, mobile] of [['pay843@audit842.test', '/company/workforce/payroll', 'paie', false], ['owner.at4f8@audit842.test', '/home', 'proprietaire-services-mobile', true]]) {
    const ctx = await browser.newContext(mobile ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } : {})
    const page = await ctx.newPage()
    page.setDefaultTimeout(15000)
    const problems = watch(page)
    await loginCompany(page, email, PASSWORD)
    await open(page, path)
    await expect(page.getByTestId('company-forbidden')).toHaveCount(0)
    if (mobile) await noHorizontalScroll(page)
    await shot(page, `D-${label}`)
    expect(problems, label).toEqual([])
    await ctx.close()
  }
})
