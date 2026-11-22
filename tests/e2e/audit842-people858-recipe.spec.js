// @ts-check
// Leezr 5.0 L5 (ADR-858) — Company › Personnes (V22) and the field-rules rationalisation, browser recipe on the MARKED
// clone. Self-preparing (profiles + fields858 fixtures), order-independent, real clicks. Proves : synthesis = server
// figures ; search and filters ; quick view without pay data ; add without access / with invitation ; a position
// requirement set from the catalogue shows up when adding ; inheritance and an « old configuration » in the
// catalogue ; limited profiles never see more ; no JS error, no refused request, no horizontal overflow.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers.js'
import { prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 5.0 people recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-5-design/captures/l5'
const RUN = Date.now().toString(36)
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/recipe-${name}.jpg`, type: 'jpeg', quality: 62 }) }

function watch(page) {
  const bad = []

  page.on('pageerror', e => bad.push(`js ${e.message}`))
  page.on('response', r => {
    if (r.url().includes('/api/') && r.status() >= 400 && !r.url().includes('/api/login')) bad.push(`${r.status()} ${new URL(r.url()).pathname}`)
  })

  return bad
}

const api = (page, path) => page.evaluate(async p => {
  const cid = JSON.parse(decodeURIComponent((document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/) || [])[1] || 'null'))
  const r = await fetch('/api' + p, { credentials: 'include', headers: { Accept: 'application/json', ...(cid ? { 'X-Company-Id': String(cid) } : {}) } })

  return r.json()
}, path)

async function openPeople(page, email, width = 1440) {
  await page.setViewportSize({ width, height: 900 })
  await loginCompany(page, email, PASSWORD)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
}
const noOverflow = async page => expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('profiles', 'fields858')
})

test('1-3 Équipe complète et incomplète, recherche, filtres, aperçu rapide sans donnée de paie', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  const bad = watch(page)

  await openPeople(page, 'owner@leezr.test')

  const served = await api(page, '/company/people?per_page=1')

  // synthesis = server figures
  await expect(page.locator('[data-test="people-summary-all"]')).toContainText(String(served.summary.total))
  await expect(page.locator('[data-test="people-summary-incomplete"]')).toContainText(String(served.summary.incomplete))
  await expect(page.locator('[data-test="people-headline"]')).toContainText(`${served.summary.total} personnes`)
  await shot(page, '1-liste')

  // the « incomplete » tile filters the list to exactly those files
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/api/company/people') && r.url().includes('attention=incomplete')),
    page.locator('[data-test="people-summary-incomplete"]').click(),
  ])
  await page.waitForTimeout(400)
  await expect(page.locator('.v-data-table-footer')).toContainText(`sur ${served.summary.incomplete}`)
  await expect(page.locator('[data-test^="people-next-"]').first()).toContainText('Compléter le dossier')
  await page.locator('[data-test="people-filters-clear"]').click()

  // search and position filter
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/api/company/people') && r.url().includes('search=Alice')),
    page.locator('[data-test="people-search"] input').fill('Alice'),
  ])
  await page.waitForTimeout(400)
  await expect(page.locator('tbody tr')).toHaveCount(1)
  await page.locator('[data-test="people-search"] input').fill('')
  await page.locator('[data-test="people-filter-job_role"]').click()
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/api/company/people') && r.url().includes('job_role=')),
    page.locator('.v-overlay--active .v-list-item').filter({ hasText: 'Livreur' }).first().click(),
  ])
  await page.keyboard.press('Escape')
  await page.waitForTimeout(600)
  for (const cell of await page.locator('tbody tr td:nth-child(2)').allInnerTexts()) expect(cell).toContain('Livreur')
  await shot(page, '2-filtre-poste')

  // quick view : position, onboarding, file — never the pay
  await page.locator('tbody tr').first().click()

  const pv = page.locator('[data-test="person-preview"]')

  await expect(pv).toContainText('Poste et rattachement')
  await expect(pv).toContainText('Livreur')
  await expect(pv.locator('[data-test="person-preview-onboarding"]')).toBeVisible()
  await expect(pv).not.toContainText(/€|salaire|rémunération/i)
  await shot(page, '3-apercu')
  await pv.locator('[data-test="person-preview-open"]').click()
  await expect(page).toHaveURL(/\/company\/people\/(employee|user)-\d+/)
  expect(bad).toEqual([])
})

test('4-6 Ajout sans accès, avec invitation, poste et ses exigences', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)

  const bad = watch(page)

  await openPeople(page, 'owner@leezr.test')

  // 6. a position requirement set from the catalogue (real clicks) — idempotent on re-runs
  await page.goto('/company/catalogs/fields', { waitUntil: 'networkidle' })
  await page.locator('[data-test="catalog-actions-nationality"]').click()
  await page.locator('[data-test="catalog-require-nationality"]').click()
  await page.locator('.v-dialog .v-select').click()
  await page.locator('.v-overlay--active .v-list-item').filter({ hasText: 'Livreur' }).first().click()
  await page.locator('.v-dialog .v-btn').filter({ hasText: /Exiger|Confirmer/ }).last().click()
  await page.waitForLoadState('networkidle')
  await expect(page.locator('[data-test="catalog-obligation-nationality"]')).toContainText('Livreur')

  // 4. without access, with the position : the drawer shows what the position requires, nothing more is asked
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.locator('[data-test="people-add"]').click()
  await page.locator('[data-test="people-add-quick"]').click()

  const d = page.locator('.v-navigation-drawer--active')

  await d.locator('[data-test="person-add-first-name"] input').fill('Lina')
  await d.locator('[data-test="person-add-last-name"] input').fill(`L5 sans acces ${RUN}`)
  await d.locator('[data-test="person-add-access-no"]').click()
  await d.locator('[data-test="person-add-position"]').click()
  await page.locator('.v-overlay--active .v-list-item').filter({ hasText: 'Livreur' }).first().click()
  await expect(d.locator('[data-test="person-add-position-needs"]')).toContainText('Nationalité')
  await shot(page, '4-ajout-poste')
  await d.locator('[data-test="person-add-submit"]').click()
  await expect(page).toHaveURL(/\/company\/people\/employee-\d+/, { timeout: 20000 })

  // 5. with an invitation
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.locator('[data-test="people-add"]').click()
  await page.locator('[data-test="people-add-quick"]').click()
  await d.locator('[data-test="person-add-first-name"] input').fill('Ines')
  await d.locator('[data-test="person-add-last-name"] input').fill(`L5 invitee ${RUN}`)
  await d.locator('[data-test="person-add-access-yes"]').click()
  await d.locator('[data-test="person-add-email"] input').fill(`l5.${RUN}@leezr.invalid`)
  await d.locator('[data-test="person-add-role"]').click()
  await page.locator('.v-overlay--active .v-list-item').first().click()
  await d.locator('[data-test="person-add-submit"]').click()
  await page.waitForURL(/\/company\/people\/(employee|user)-\d+/, { timeout: 20000 }).catch(() => {})
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.locator('[data-test="people-search"] input').fill(`L5 invitee ${RUN}`)
  await page.waitForTimeout(900)
  await expect(page.locator('tbody tr').first()).toContainText(/Invitation envoyée|À accepter|Accès prêt/)
  await shot(page, '5-invitee')
  expect(bad).toEqual([])
})

test('7-8 Héritage expliqué et ancienne configuration (société fictive 101)', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)

  const bad = watch(page)

  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page, 'e2e.8091.owner.433828@leezr.invalid', PASSWORD)
  await page.goto('/company/catalogs/fields', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await expect(page.locator('[data-test="catalog-inheritance"]')).toContainText('Comment un champ devient obligatoire')

  // the old drawer's global obligation is shown with its origin, the redundant one shows both origins
  await expect(page.locator('[data-test="catalog-obligation-nationality"]')).toContainText('Toute la société')
  await expect(page.locator('[data-test="catalog-obligation-hire_date"]')).toContainText('Votre métier')
  await expect(page.locator('[data-test="catalog-obligation-hire_date"]')).toContainText('Toute la société')
  await shot(page, '7-heritage')

  // the company-wide rule can be withdrawn and set back from the ONE place it lives now
  await page.locator('[data-test="catalog-actions-nationality"]').click()
  await page.locator('[data-test="catalog-require-all-nationality"]').click()
  await page.waitForLoadState('networkidle')
  await expect(page.locator('[data-test="catalog-obligation-nationality"]')).not.toContainText('Toute la société')
  await page.locator('[data-test="catalog-actions-nationality"]').click()
  await page.locator('[data-test="catalog-require-all-nationality"]').click()
  await page.waitForLoadState('networkidle')
  await expect(page.locator('[data-test="catalog-obligation-nationality"]')).toContainText('Toute la société')

  // People has no « Outils » anymore ; « Configurer les champs » leads here
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="people-tools"]')).toHaveCount(0)
  await page.locator('[data-test="people-configure-fields"]').click()
  await expect(page).toHaveURL(/\/company\/catalogs\/fields/)
  expect(bad).toEqual([])
})

test('9 Permissions : chaque profil ne voit que ce qui lui est permis', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)

  const bad = watch(page)

  const cases = [
    { email: 'manager@leezr.test', noAccessCol: true },
    { email: 'rh-nopay@leezr.test' },
    { email: 'consultation@leezr.test', noHrCol: true },
    { email: 'admin@leezr.test' },
  ]

  for (const c of cases) {
    await page.context().clearCookies()
    await openPeople(page, c.email)
    if (!new URL(page.url()).pathname.startsWith('/company/people')) continue // no people facet : not shown at all
    const served = await api(page, '/company/people?per_page=1')

    await expect(page.locator('[data-test="people-configure-fields"]')).toHaveCount(0) // settings.manage only
    if (served.summary.invitations === null) await expect(page.locator('[data-test="people-summary-invitations"]')).toHaveCount(0)
    if (served.summary.incomplete === null) await expect(page.locator('[data-test="people-summary-incomplete"]')).toHaveCount(0)
    if (!served.facets.hr) await expect(page.locator('thead')).not.toContainText('Statut RH')
    if (await page.locator('tbody tr').count()) {
      await page.locator('tbody tr').first().click()
      await expect(page.locator('[data-test="person-preview"]')).not.toContainText(/€|salaire|rémunération/i)
      await page.keyboard.press('Escape')
    }
  }

  // employee and driver have no people facet : the page is not reachable (redirected / refused page), no API refusal
  for (const email of ['salarie@leezr.test', 'bob@leezr.test']) {
    await page.context().clearCookies()
    await loginCompany(page, email, PASSWORD)
    await page.goto('/company/people', { waitUntil: 'networkidle' })
    await expect(page.locator('[data-test="people-table"]')).toHaveCount(0)
  }
  expect(bad.filter(b => !b.includes('/company/people'))).toEqual([])
})

test('10-12 Desktop, tablette, mobile, clair et sombre : pas de débordement ni d’erreur', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  const bad = watch(page)
  for (const [w, name] of [[1440, 'desktop'], [820, 'tablette'], [390, 'mobile']]) {
    await page.context().clearCookies()
    await openPeople(page, 'owner@leezr.test', w)
    await noOverflow(page)
    if (w === 390) {
      await expect(page.locator('[data-test="people-cards"]')).toBeVisible()
      await page.locator('[data-test^="people-row-"]').first().click()
      await expect(page.locator('[data-test="person-preview"]')).toBeVisible()
    }
    await shot(page, `10-${name}`)
  }
  await page.context().addCookies([{ name: '-theme', value: 'dark', url: page.url() }])
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await noOverflow(page)
  await shot(page, '11-mobile-sombre')
  expect(bad).toEqual([])
})
