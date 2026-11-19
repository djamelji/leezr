// @ts-check
// ADR-690 (WORKFORCE EXPERIENCE 2026 / E2) — RECETTE navigateur multi-permissions.
// Chaque utilisateur fictif voit EXACTEMENT les entrées d'activité correspondant à ses capacités
// réelles (le refus serveur est prouvé par WorkforceNavE2RecetteTest). Responsive 1920/1366/768/390,
// dark, drawer mobile, états actifs, préservation des deep-links historiques.
//
// Les entrées sont ciblées par HREF (sans ambiguïté) : les liens /company/workforce/<space> sont
// propres à l'espace RH (le « Documents » d'ENTREPRISE pointe vers /company/documents, pas workforce).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

const PW = 'password'
const shot = name => test.info().outputPath(`${name}.png`)

// ADR-831 (D-21 bis) — the matrix below is the one ADR-691 (E2.1) set right after ADR-690 (« Resp. exploitation »
// sees Collaborateurs, Temps, Organisation, Paramètres) ; the hrefs follow the current navigation : Collaborateurs
// = Équipe (/company/people, ADR-802), documents = Documents space (/company/documents, ADR-802), Organisation and
// HR settings live in the Réglages hub (/company/settings, ADR-818) — the « Ressources humaines » group is gone.
// space => fragment d'href unique dans la sidebar.
const HREF = {
  employees: '/company/people',
  time: '/company/workforce/time',
  leave: '/company/workforce/leave',
  documents: '/company/documents',
  payroll: '/company/workforce/payroll',
  organization: '/company/settings',
  settings: '/company/settings',
}

// Widgets du dashboard Core peuvent émettre des 403 bénins (permissions Core absentes pour un profil
// RH minimal) — hors périmètre nav E2 ; on ne fait pas échouer la recette nav pour ce bruit.
const benign = m => /permission required|request failed|403|forbidden/i.test(m)

async function assertNav(page, present, absent) {
  const nav = page.locator('.layout-vertical-nav')
  await expect(nav.locator('a[href="/home"]').first()).toBeVisible({ timeout: 10000 })
  for (const key of present)
    // entries of a collapsed group (Équipe, Planning & temps) are rendered but folded : attached = granted entry
    await expect(nav.locator(`a[href*="${HREF[key]}"]`).first(), `présent: ${key}`).toBeAttached({ timeout: 8000 })
  for (const key of absent)
    await expect(nav.locator(`a[href*="${HREF[key]}"]`), `absent: ${key}`).toHaveCount(0)
}

const PERSONAS = [
  { email: 'rh-nopay@leezr.test', present: ['employees', 'time', 'leave', 'documents', 'organization', 'settings'], absent: ['payroll'] },
  { email: 'ops@leezr.test', present: ['employees', 'time', 'organization', 'settings'], absent: ['leave', 'documents', 'payroll'] },
  { email: 'pay@leezr.test', present: ['payroll'], absent: ['employees', 'time', 'leave', 'documents', 'organization', 'settings'] },
  { email: 'salarie@leezr.test', present: [], absent: ['employees', 'leave', 'documents', 'payroll', 'organization', 'settings'] },
]

for (const p of PERSONAS) {
  test(`nav reflète les capacités de ${p.email}`, async ({ page }) => {
    const errors = []
    page.on('pageerror', e => { if (!benign(e.message)) errors.push(e.message) })
    await loginCompany(page, p.email, PW)
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await assertNav(page, p.present, p.absent)
    await page.screenshot({ path: shot(`e2-nav-${p.email.split('@')[0]}`), fullPage: false })
    expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
  })
}

test('salarié : « Mon espace » présent, pas de menu Congés management', async ({ page }) => {
  await loginCompany(page, 'salarie@leezr.test', PW)
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  const nav = page.locator('.layout-vertical-nav')
  await expect(nav.locator('a[href*="/company/workforce/me"]').first()).toBeVisible({ timeout: 10000 })
  await expect(nav.locator(`a[href*="${HREF.leave}"]`)).toHaveCount(0)
})

test('owner : responsive + dark + états actifs', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => { if (!benign(e.message)) errors.push(e.message) })
  await loginCompany(page, 'owner@leezr.test', PW)

  // Responsive desktop/tablette.
  for (const vp of [{ w: 1920, h: 1080 }, { w: 1366, h: 768 }, { w: 768, h: 1024 }]) {
    await page.setViewportSize({ width: vp.w, height: vp.h })
    await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
    // below 1280 px the Vuexy vertical nav is an overlay (hamburger) : the entry exists but is folded away
    // the « Équipe » group is not auto-expanded on its sub-pages (Vuexy matches the exact route) : open it if folded
    const entry = page.locator(`.layout-vertical-nav a[href*="${HREF.employees}"]`).first()
    if (vp.w >= 1280) {
      if (!(await entry.isVisible()))
        await page.locator('.layout-vertical-nav .nav-group-label', { hasText: 'Équipe' }).first().click()
      await expect(entry).toBeVisible({ timeout: 10000 })
    }
    else { await expect(entry).toBeAttached({ timeout: 10000 }) }
    await page.screenshot({ path: shot(`e2-owner-${vp.w}`), fullPage: false })
  }

  // État actif : le lien de la route courante porte la classe active de Vue Router (sur le <a>).
  await page.setViewportSize({ width: 1366, height: 768 })
  await page.goto('/company/workforce/payroll', { waitUntil: 'domcontentloaded' })
  const payrollLink = page.locator('.layout-vertical-nav a[href*="/company/workforce/payroll"]').first()
  await expect(payrollLink).toHaveClass(/router-link-active/, { timeout: 10000 })

  // Dark.
  await page.evaluate(() => document.documentElement.classList.add('v-theme--dark'))
  await page.screenshot({ path: shot('e2-owner-dark'), fullPage: false })

  expect(errors, `JS errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('deep-links historiques préservés (anciennes URLs toujours routables)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test', PW)
  for (const url of ['/company/workforce/dsn', '/company/workforce/configuration', '/company/workforce']) {
    await page.goto(url, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(1500) // laisser les redirections synchrones s'appliquer
    // L'app shell est rendue (nav visible) et on reste dans l'espace Company (pas de 404/login).
    await expect(page.locator('.layout-vertical-nav').first(), `shell pour ${url}`).toBeVisible({ timeout: 10000 })
    // ADR-818 : /company/workforce lands on the single Company home (/home)
    expect(page.url(), `deep-link ${url} reste dans Company`).toMatch(/\/(company|dashboard|home)/)
    expect(page.url(), `deep-link ${url} pas de 404`).not.toContain('not-found')
  }
})

test('mobile 390 : drawer de navigation ouvrable et navigable', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page, 'owner@leezr.test', PW)
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })

  const toggle = page.locator('.header-action .tabler-menu-2, .nav-toggle, i.tabler-menu-2').first()
  await toggle.click({ timeout: 8000 }).catch(() => {})
  await page.waitForTimeout(600)
  const nav = page.locator('.layout-vertical-nav')
  const people = nav.locator(`a[href*="${HREF.employees}"]`).first()
  if (!(await people.isVisible()))
    await nav.locator('.nav-group-label', { hasText: 'Équipe' }).first().click()
  await expect(people).toBeVisible({ timeout: 8000 })
  await people.click()
  await expect(page).toHaveURL(/\/company\/people/, { timeout: 10000 })
  await page.screenshot({ path: shot('e2-mobile-390'), fullPage: false })
})
