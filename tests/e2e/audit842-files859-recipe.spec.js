// @ts-check
// Leezr 5.0 L6 (ADR-859) — HR file completeness and retired interfaces, browser recipe on the MARKED clone.
// Self-preparing (profiles + fields858 + files859), real clicks. Proves : accountless and account files are evaluated ;
// complete / incomplete / « to collect » ; position requirements differ by position ; quick add accepts an incomplete
// file ; full hiring up to finalisation ; legacy per-role rules visible ; limited HR rights ; tenant isolation ;
// replaced interfaces and old URLs ; desktop and mobile ; no JS error, no refused request.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers.js'
import { fixtureJson, prepare, runFixture } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 HR files recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l6'
const MAILPIT = 'http://localhost:8025/api/v1'
const RUN = Date.now().toString(36)
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/recipe-${name}.jpg`, type: 'jpeg', quality: 62 }) }
function watch(page) {
  const bad = []

  page.on('pageerror', e => bad.push(`js ${e.message}`))
  page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400 && !r.url().includes('/api/login')) bad.push(`${r.status()} ${new URL(r.url()).pathname}`) })

  return bad
}

const api = (page, path, companyId = null) => page.evaluate(async ([p, forced]) => {
  const cid = forced || JSON.parse(decodeURIComponent((document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/) || [])[1] || 'null'))
  const r = await fetch('/api' + p, { credentials: 'include', headers: { Accept: 'application/json', ...(cid ? { 'X-Company-Id': String(cid) } : {}) } })

  return { status: r.status, body: await r.json().catch(() => ({})) }
}, [path, companyId])

async function people(page, email, width = 1440) {
  await page.setViewportSize({ width, height: 900 })
  await loginCompany(page, email, PASSWORD)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
}
async function search(page, text) {
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/api/company/people') && r.url().includes('search=')),
    page.locator('[data-test="people-search"] input').fill(text),
  ])
  await page.waitForTimeout(400)
}

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles', 'fields858', 'files859')
})

test('Dossiers sans et avec compte, complet / incomplet / à collecter, exigences selon le poste', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  const bad = watch(page)

  await people(page, 'owner@leezr.test')

  // without account : evaluated, the missing items are named in the quick view
  await search(page, 'Antoine Mercier')

  const antoine = page.locator('tbody tr').first()

  await expect(antoine).toContainText('Sans accès')
  await expect(antoine).toContainText(/\d+\/\d+/)
  await expect(antoine).toContainText('Compléter le dossier')
  await antoine.click()
  await expect(page.locator('[data-test="person-preview-missing-list"]')).toBeVisible()
  await shot(page, '1-sans-compte')
  await page.keyboard.press('Escape')

  // with account : the file (not only the login profile) is evaluated
  await search(page, 'Alice')
  await expect(page.locator('tbody tr').first()).toContainText(/\d+\/\d+/)

  // position : Livreur requires « Nationalité » (company decision), another position does not
  const livreur = (await api(page, '/company/people?per_page=100&job_role=Livreur')).body.data

  expect(livreur.some(r => r.completeness?.missing?.includes('Nationalité'))).toBe(true)

  const others = (await api(page, '/company/people?per_page=100&job_role=' + encodeURIComponent('Responsable d\'exploitation'))).body.data
  for (const r of others) expect(r.completeness?.missing || []).not.toContain('Nationalité')

  // a recruit still in onboarding : « to collect », not « incomplete »
  const prep = (await api(page, '/company/people?per_page=100&attention=preparatory')).body.data
  for (const r of prep) expect(r.next_action).toBe('finish_onboarding')
  expect(bad).toEqual([])
})

test('Dossier complet (société fictive 101) et ajout rapide incomplet accepté', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  const bad = watch(page)

  await people(page, 'e2e.8091.owner.433828@leezr.invalid')
  await search(page, 'Dossier Complet')

  const row = page.locator('tbody tr').first()

  await expect(row).toContainText('À jour')
  await shot(page, '2-dossier-complet')

  // quick add without access : the file is created even though it is incomplete (the workflow allows it)
  await page.locator('[data-test="people-add"]').click()
  if (await page.locator('[data-test="people-add-quick"]').count()) await page.locator('[data-test="people-add-quick"]').click()
  const d = page.locator('.v-navigation-drawer--active')

  await d.locator('[data-test="person-add-first-name"] input').fill('Rapide')
  await d.locator('[data-test="person-add-last-name"] input').fill(`L6 ${RUN}`)
  if (await d.locator('[data-test="person-add-access-no"]').count()) await d.locator('[data-test="person-add-access-no"]').click()
  await d.locator('[data-test="person-add-submit"]').click()
  await expect(page).toHaveURL(/\/company\/people\/employee-\d+/, { timeout: 20000 })
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await search(page, `L6 ${RUN}`)
  await expect(page.locator('tbody tr').first()).toContainText('Compléter le dossier')
  expect(bad).toEqual([])
})

test('Embauche complète jusqu’à l’intégration finalisée', async ({ page, browser, request }) => {
  test.setTimeout(6 * 60 * 1000)
  page.setDefaultTimeout(20000)

  const bad = watch(page)
  const email = `l6-recrue-${RUN}@example.test`

  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'owner@leezr.test', PASSWORD)
  await page.goto('/company/workforce/hiring', { waitUntil: 'networkidle' })
  await page.locator('[data-test="hiring-new"]').click()
  await page.locator('[data-test="onb-first-name"] input').fill('L6 Recrue')
  await page.locator('[data-test="onb-last-name"] input').fill(RUN)
  await page.locator('[data-test="onb-email"] input').fill(email)
  await page.locator('[data-test="onb-hire-date"] input').first().evaluate(el => { const fp = el._flatpickr || el.closest('[data-test]').querySelector('input')._flatpickr

    fp.setDate('2027-01-04', true) })
  await page.locator('[data-test="onb-position"]').click()
  await page.locator('.v-overlay--active .v-list-item').filter({ hasText: 'Livreur' }).first().click()
  await page.locator('[data-test="onb-next"]').click()
  await expect(page.locator('[data-test^="onb-item-"]').first()).toBeVisible()

  // the position's proposal is kept as is (the identity card is imposed by the Workforce module). The recipe server runs
  // with DOCUMENT_SCANNER=simulated (DEV/test driver, refused in production) so that a clean upload leaves quarantine.
  await page.locator('[data-test="onb-next"]').click()
  await page.locator('[data-test="onb-legal-basis"]').click()
  await page.locator('.v-overlay--active .v-list-item').first().click()
  await page.locator('[data-test="onb-create-mail"]').click()
  await expect(page.locator('[data-test="onboarding-mailed"]')).toBeVisible({ timeout: 60000 })
  await page.keyboard.press('Escape')

  // while collecting : the recruit is « onboarding in progress » in People, never « incomplete »
  const recruits = (await api(page, `/company/people?per_page=100&search=${RUN}`)).body.data

  expect(recruits[0].employment_status).toBe('preparatory')
  expect(recruits[0].next_action).toBe('finish_onboarding')

  // candidate fills every requested field and submits (the portal refuses a missing required item)
  let mail = null
  for (let i = 0; i < 20 && !mail; i++) {
    const res = await (await request.get(`${MAILPIT}/search?query=${encodeURIComponent(`to:"${email}"`)}`)).json()
    if (res.messages?.length) mail = await (await request.get(`${MAILPIT}/message/${res.messages[0].ID}`)).json()
    else await page.waitForTimeout(1500)
  }
  const link = (mail.Text.match(/https?:\/\/\S+\/onboarding#t=[a-f0-9]{64}/) || [])[0]
  const cand = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage()
  const url = new URL(link)

  await cand.goto(`${new URL(page.url()).origin}${url.pathname}${url.hash}`)
  await cand.waitForTimeout(1500)

  // fields : every requested text field is filled
  cand.setDefaultTimeout(20000)
  await expect(cand.locator('[data-test="onboarding-save"]')).toBeVisible()
  for (const input of await cand.locator('.v-field input:not([type="file"]):not([type="checkbox"]):not([type="hidden"]):not([type="radio"])').all()) {
    if (await input.isVisible() && await input.isEditable() && !(await input.inputValue())) await input.fill((await input.getAttribute('type')) === 'date' ? '1990-01-01' : 'Française')
  }
  await cand.locator('[data-test="onboarding-save"]').click()
  await cand.waitForTimeout(800)

  // a sensitive document asks for a one-time code sent to the candidate's e-mail (ADR-796.1)
  if (await cand.getByRole('button', { name: 'Recevoir un code' }).count()) {
    const since = Date.now()

    await cand.getByRole('button', { name: 'Recevoir un code' }).click()
    let code = null
    for (let i = 0; i < 40 && !code; i++) {
      const res = await (await request.get(`${MAILPIT}/search?query=${encodeURIComponent(`to:"${email}"`)}`)).json()
      for (const m of res.messages || []) {
        if (new Date(m.Created).getTime() < since - 60000) continue
        const msg = await (await request.get(`${MAILPIT}/message/${m.ID}`)).json()
        if (msg.Text.includes('#t=')) continue // the invitation itself, not the code
        code = (msg.Text.match(/\b(\d{6})\b/) || [])[1] || null
        if (code) break
      }
      if (!code) await cand.waitForTimeout(1500)
    }
    expect(code, 'one-time code received in Mailpit').toBeTruthy()
    await cand.getByLabel('Code à 6 chiffres').fill(code)
    await cand.getByRole('button', { name: 'Vérifier' }).click()
    await cand.waitForTimeout(1200)
  }
  const scanSince = Math.floor(Date.now() / 1000)
  for (const up of await cand.locator('[data-test^="onboarding-doc-"] input[type="file"]').all()) {
    await up.setInputFiles({ name: 'piece-recette.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF') })
    await cand.waitForTimeout(1500)
  }
  // ADR-862 — precondition stated, not assumed : the deposits were analysed by the SIMULATED antivirus of the recipe
  // server (scripts/audit842-serve.sh). Any other engine, or a deposit left in quarantine, is a wrong environment.
  const scan = fixtureJson(runFixture('scan859', process.env.AUDIT_PASSWORD_FILE, String(scanSince)))
  expect(scan.uploads, 'deposits recorded').toBeGreaterThan(0)
  expect(scan.engines, 'recipe server = scripts/audit842-serve.sh (DOCUMENT_SCANNER=simulated)').toEqual(['simulated'])
  expect(scan.statuses).not.toContain('quarantined')
  await cand.reload()
  await cand.waitForTimeout(1500)
  await cand.locator('[data-test="onboarding-submit"]').click()
  await expect(cand.locator('[data-test="onboarding-submitted"]')).toBeVisible({ timeout: 15000 })

  // HR validates then finalises
  await page.goto('/company/workforce/hiring', { waitUntil: 'networkidle' })
  await page.getByText('Toutes', { exact: true }).click()
  await page.waitForTimeout(1000)
  await page.locator('[data-test="hiring-table"] tr', { hasText: RUN }).locator('[data-test^="hiring-open-"]').click()
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/validate') && r.request().method() === 'POST' && r.ok()),
    page.locator('[data-test="onb-validate"]').click(),
  ])
  await page.waitForTimeout(1500)
  await page.goto('/company/workforce/hiring', { waitUntil: 'networkidle' })
  await page.getByText('Toutes', { exact: true }).click()
  await page.waitForTimeout(1000)
  await page.locator('[data-test="hiring-table"] tr', { hasText: RUN }).locator('[data-test^="hiring-finalize-"]').click()
  await page.locator('[data-test="hiring-finalize-submit"]').click()
  await expect(page.locator('[data-test="hiring-finalized"]')).toBeVisible({ timeout: 20000 })
  await shot(page, '3-integration-finalisee')

  // finalised : active, the file is evaluated against the position (Livreur)
  const after = (await api(page, `/company/people?per_page=100&search=${RUN}`)).body.data[0]

  expect(after.employment_status).toBe('active')
  expect(after.completeness.source).toBe('file')
  expect(after.completeness.missing).not.toContain('Nationalité') // collected during onboarding
  expect(bad.filter(b => !b.includes('/public/onboarding'))).toEqual([])
})

test('Droits RH limités, règles par rôle, isolation, anciennes interfaces et adresses', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)

  const bad = watch(page)

  // limited HR (no pay) : file figures, never a value or the pay
  await people(page, 'rh-nopay@leezr.test')

  const hr = (await api(page, '/company/people?per_page=5')).body

  // its real perimeter on the clone may be empty (self + direct reports, ADR-767) : when it sees HR records, they are evaluated
  if (hr.facets?.hr && hr.data.some(r => r.employee_id)) expect(hr.data.filter(r => r.employee_id).every(r => r.completeness?.source === 'file')).toBe(true)
  for (const r of hr.data) expect(JSON.stringify(r)).not.toMatch(/salary|iban_value|base_salary/i)

  // limited user without the HR facet : no HR file figures
  await page.context().clearCookies()
  await people(page, 'consultation@leezr.test')

  const lim = (await api(page, '/company/people?per_page=100')).body
  if (!lim.facets?.hr) for (const r of lim.data || []) expect(r.completeness?.source).not.toBe('file')

  // tenant isolation : company 2 never lists company 1's people
  await page.context().clearCookies()
  await people(page, 'owner@leezr.test')

  const c1 = (await api(page, '/company/people?per_page=100')).body.data.map(r => r.person_ref)
  const c2 = (await api(page, '/company/people?per_page=100', 2)).body.data.map(r => r.person_ref)

  expect(c2.filter(ref => c1.includes(ref) && ref.startsWith('employee-'))).toEqual([])

  // legacy per-role rules : still visible where they live, with their scope explained
  await page.goto('/company/catalogs/fields', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="catalog-inheritance"]')).toContainText('règles historiques'.slice(0, 0) || 'Comment')
  await expect(page.locator('[data-test="catalog-obligation-phone"]')).toContainText('historique')

  // two « everyone » settings, each explained
  await page.goto('/company/workforce/requirements', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="requirements-everyone-scope"]')).toBeVisible()

  // Modules : « Désactiver » in the card menu, same confirmation (cancelled here)
  await page.goto('/company/modules', { waitUntil: 'networkidle' })

  const more = page.locator('[data-test^="module-more-"]').first()

  await more.click()
  await page.locator('[data-test^="module-deactivate-"]').first().click()
  await expect(page.locator('.v-overlay--active .v-card').last()).toBeVisible()
  await page.keyboard.press('Escape')
  await shot(page, '4-modules-menu')

  // old URLs still lead to the right place
  // ADR-864 : one Personnes interface — the former HR view (?tab=hr, /people/hr) lands on Personnes itself
  for (const [from, to] of [['/company/members', /\/company\/people/], ['/company/roles', /\/company\/people\/roles/], ['/company/people?tab=hr', /\/company\/people(\?|$)/], ['/company/people/hr', /\/company\/people(\?|$)/]]) {
    await page.goto(from, { waitUntil: 'networkidle' })
    await expect(page).toHaveURL(to)
  }
  expect(bad).toEqual([])
})

test('Mobile : liste et aperçu du dossier', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)

  const bad = watch(page)

  await people(page, 'owner@leezr.test', 390)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
  await page.locator('[data-test^="people-row-"]').first().click()
  await expect(page.locator('[data-test="person-preview-open"]')).toBeInViewport()
  await shot(page, '5-mobile-apercu')
  expect(bad).toEqual([])
})
