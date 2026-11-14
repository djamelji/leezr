// @ts-check
// ADR-775 (Company Experience 2.0 / UX0) — navigation par intention, dans un vrai navigateur, par
// profil réel. LECTURE SEULE (aucune donnée créée). Pour chaque profil : menu servi, ordre, chaque
// entrée ouverte, actions d'accueil, menu de l'avatar, anciens liens — et AUCUN refus réseau.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

const OUT = 'docs/bmad/ux0-775'
const ORDER = ['home', 'team', 'time', 'documents', 'payroll', 'activity', 'modules', 'settings', 'personal'] // ADR-782 : Modules
const PROFILES = [
  { tag: 'owner', email: 'owner@leezr.test', note: 'propriétaire, aussi salarié' },
  { tag: 'dispatcher', email: 'admin@leezr.test', note: 'administratif, aussi salarié' },
  { tag: 'manager', email: 'manager@leezr.test', note: 'manager à permissions limitées' },
  { tag: 'rh', email: 'rh-nopay@leezr.test', note: 'RH sans paie' },
  { tag: 'paie', email: 'pay@leezr.test', note: 'gestionnaire paie' },
  { tag: 'consultation', email: 'consultation@leezr.test', note: 'lecture seule' },
  { tag: 'driver', email: 'bob@leezr.test', note: 'salarié sans gestion' },
  { tag: 'salarie', email: 'salarie@leezr.test', note: 'salarié sans gestion' },
]
const LEGACY = ['/company/roles', '/company/plan', '/company/workforce', '/company/workforce/documents', '/company/workforce/configuration', '/company/members']

const watch = page => {
  const bad = []
  let at = ''
  page.on('response', r => { if (r.status() >= 400 && r.url().includes('/api/')) bad.push({ at, status: r.status(), url: r.url().replace(/^https?:\/\/[^/]+/, '').slice(0, 110) }) })
  page.on('pageerror', e => bad.push({ at, js: String(e.message).slice(0, 160) }))
  return { bad, mark: v => { at = v } }
}
const push = async (page, to) => {
  await page.evaluate(async t => { const r = document.querySelector('#app').__vue_app__.config.globalProperties.$router; await r.push(t).catch(() => {}) }, to)
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
  await page.waitForTimeout(700)
  return new URL(page.url()).pathname
}
const leaves = groups => groups.flatMap(g => g.items.flatMap(i => (i.children?.length ? i.children : [i]).map(l => ({ ...l, space: g.key.replace(/_menu$/, ''), placement: g.placement }))))

for (const p of PROFILES) {
  test(`UX0 — ${p.tag} (${p.note})`, async ({ page }) => {
    test.setTimeout(6 * 60 * 1000)
    const net = watch(page)
    await loginCompany(page, p.email)
    const nav = (await apiRequest(page, { path: '/api/nav' })).json
    const side = nav.groups.filter(g => g.placement === 'nav')
    const menu = nav.groups.filter(g => g.placement === 'menu')

    // Ordre validé, accueil en premier, aucune section vide, une entrée principale par intention.
    const spaces = side.map(g => g.key)
    expect(spaces[0]).toBe('home')
    expect(spaces).toEqual(ORDER.filter(k => spaces.includes(k)))
    for (const g of side) {
      expect(g.items.length, `${g.key} non vide`).toBeGreaterThan(0)
      if (g.key !== 'personal') expect(g.items.length, `${g.key} : une entrée`).toBe(1)
    }
    const hasManagement = side.some(g => ['team', 'documents', 'payroll', 'settings'].includes(g.key))
    expect(side.some(g => g.key === 'personal')).toBe(!hasManagement)

    // Le menu AFFICHÉ correspond au menu servi.
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('.layout-vertical-nav .nav-link, .layout-vertical-nav .nav-group', { timeout: 20000 })
    await page.waitForTimeout(1500)
    const shown = await page.locator('.layout-vertical-nav .nav-items > li').count()
    const entries = side.reduce((n, g) => n + g.items.length, 0)
    expect(shown, 'entrées affichées = entrées servies').toBe(entries)
    await page.screenshot({ path: `${OUT}/after-${p.tag}-desktop.jpg`, type: 'jpeg', quality: 55 })

    // Chaque entrée s'ouvre : aucune page 403, aucun refus réseau.
    const opened = []
    for (const leaf of leaves(nav.groups)) {
      net.mark(`entrée ${leaf.key}`)
      const path = await push(page, leaf.to)
      expect(path, `${leaf.key} → pas de 403`).not.toContain('/403')
      opened.push({ key: leaf.key, space: leaf.space, placement: leaf.placement, path })
    }

    // Actions d'accueil : toutes autorisées.
    await push(page, '/dashboard')
    const homeActions = (nav.actions || []).filter(a => a.home && a.to)
    for (const a of homeActions) {
      net.mark(`action ${a.key}`)
      await push(page, '/dashboard')
      const btn = page.locator(`[data-test="home-action-${a.key}"]`)
      if (await btn.count()) {
        await btn.click()
        await page.waitForTimeout(1200)
        expect(new URL(page.url()).pathname, `${a.key} → pas de 403`).not.toContain('/403')
        await page.keyboard.press('Escape')
      }
    }

    // Menu de l'avatar : entrées personnelles servies.
    net.mark('avatar')
    await push(page, '/dashboard')
    await page.locator('.layout-navbar .v-avatar').first().click()
    await page.waitForTimeout(600)
    for (const item of menu.flatMap(g => g.items)) await expect(page.locator(`[data-test="avatar-menu-${item.key}"]`)).toBeVisible()
    await page.screenshot({ path: `${OUT}/after-${p.tag}-avatar.jpg`, type: 'jpeg', quality: 55 })
    await page.keyboard.press('Escape')

    // Anciens liens : jamais d'erreur ; redirection ou page, ou refus PROPRE si le droit manque.
    const legacy = []
    for (const old of LEGACY) {
      net.mark(`ancien lien ${old}`)
      legacy.push({ from: old, to: await push(page, old) })
    }

    const refused = net.bad.filter(b => !b.at.startsWith('ancien lien'))
    fs.writeFileSync(`${OUT}/result-${p.tag}.json`, JSON.stringify({ profile: p, spaces, entries, leaves: opened.length, personalInMenu: menu.flatMap(g => g.items.map(i => i.key)), homeActions: homeActions.map(a => a.key), opened, legacy, network: net.bad }, null, 1))
    expect(refused, 'aucun refus réseau ni erreur JS sur le menu, les actions et l\'avatar').toEqual([])
  })
}

test('UX0 — changement de Company : Workforce → sans Workforce', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const net = watch(page)
  await loginCompany(page, 'owner@leezr.test')
  const before = (await apiRequest(page, { path: '/api/nav' })).json.groups.filter(g => g.placement === 'nav').map(g => g.key)
  expect(before).toContain('time')

  const companies = (await apiRequest(page, { path: '/api/my-companies' })).json
  const list = companies.companies || companies.data || companies
  const other = list.find(c => /Trial Logistics/.test(c.name))
  test.skip(!other, 'pas d\'entreprise sans Workforce pour ce compte')
  // Même geste que le sélecteur d'entreprise : bascule puis rechargement de la page.
  await page.evaluate(id => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').switchCompany(id), other.id)
  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForSelector('.layout-vertical-nav .nav-link', { timeout: 20000 })
  await page.waitForTimeout(2000)
  const after = (await apiRequest(page, { path: '/api/nav' })).json.groups.filter(g => g.placement === 'nav').map(g => g.key)
  expect(after[0]).toBe('home')
  expect(after).not.toContain('time')
  expect(after).not.toContain('payroll')
  const labels = await page.locator('.layout-vertical-nav .nav-items > li').allInnerTexts()
  expect(labels.join('|')).not.toMatch(/Planning|Paie/)
  await page.screenshot({ path: `${OUT}/after-owner-no-workforce.jpg`, type: 'jpeg', quality: 55 })
  fs.writeFileSync(`${OUT}/result-company-switch.json`, JSON.stringify({ before, after, labels, network: net.bad }, null, 1))

  // Retour à l'entreprise d'origine (état du compte de test inchangé).
  const home = list.find(c => !/Trial Logistics|M6E LOAD/.test(c.name))
  // Même geste que le sélecteur d'entreprise : bascule puis rechargement de la page.
  await page.evaluate(id => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').switchCompany(id), home.id)
  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2000)
  expect(net.bad).toEqual([])
})
