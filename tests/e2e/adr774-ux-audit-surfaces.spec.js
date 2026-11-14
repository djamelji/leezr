// @ts-check
// ADR-774 — AUDIT (lecture seule) : inventaire mesuré des surfaces Company telles que servies à un
// compte réel. Aucune écriture. Produit docs/bmad/ux-774/surfaces-<profil>.json + captures.
import fs from 'node:fs'
import { test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

const OUT = process.env.AUDIT_OUT || 'docs/bmad/ux-774'
const PROFILE = process.env.AUDIT_USER || 'owner@leezr.test'
const TAG = process.env.AUDIT_TAG || 'owner'
const EXTRA = (process.env.AUDIT_EXTRA || '').split(',').filter(Boolean)

const flatten = (items, group = null, depth = 0, out = []) => {
  for (const it of items || []) {
    const title = it.title || it.heading || it.key
    if (it.heading && !it.to) { flatten(it.children || [], title, depth, out); group = title; continue }
    if ((it.to && Object.keys(it.to).length) || it.href) out.push({ group, depth, title, key: it.key, to: it.to })
    if (it.children) flatten(it.children, group, depth + 1, out)
  }
  return out
}

test(`surfaces Company — ${TAG}`, async ({ page }) => {
  test.setTimeout(25 * 60 * 1000)
  const errors = []
  let current = null
  page.on('response', r => { if (r.status() >= 400 && r.url().includes('/api/')) errors.push({ page: current, status: r.status(), method: r.request().method(), url: r.url().replace(/^https?:\/\/[^/]+/, '') }) })
  page.on('pageerror', e => errors.push({ page: current, js: String(e.message).slice(0, 200) }))
  page.on('console', m => { if (m.type() === 'error' && !/ERR_NETWORK_CHANGED/.test(m.text())) errors.push({ page: current, console: m.text().slice(0, 200) }) })

  await loginCompany(page, PROFILE)
  const nav = await apiRequest(page, { path: '/api/nav' })
  fs.writeFileSync(`${OUT}/nav-${TAG}.json`, JSON.stringify(nav.json, null, 1))
  const landing = new URL(page.url()).pathname

  // Resolve each nav entry to a path through the router.
  const entries = (nav.json.groups || []).flatMap(g => flatten(g.items, g.key))
  for (const f of nav.json.footer_links || []) if (f.to) entries.push({ group: 'footer', depth: 0, title: f.title || f.key, key: f.key, to: f.to })
  const paths = await page.evaluate(list => {
    const router = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router
    return list.map(e => { try { return typeof e.to === 'string' ? e.to : router.resolve(e.to).path } catch { return null } })
  }, entries)

  const surfaces = []
  const targets = [...entries.map((e, i) => ({ ...e, path: paths[i] })), ...EXTRA.map(p => ({ group: 'hors menu', title: p, path: p }))]
  let n = 0
  for (const t of targets) {
    if (!t.path) { surfaces.push({ ...t, unresolved: true }); continue }
    current = t.path
    const started = Date.now()
    // Navigation DANS l'application (comme un utilisateur) ; rechargement complet en repli.
    const pushed = await page.evaluate(async p => {
      const router = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router
      if (!router) return false
      await router.push(p).catch(() => {})
      return true
    }, t.path).catch(() => false)
    if (!pushed) await page.goto(t.path, { waitUntil: 'domcontentloaded' })
    await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {})
    await page.waitForFunction(() => (document.querySelector('.layout-page-content')?.innerText || '').trim().length > 20, null, { timeout: 20000 }).catch(() => {})
    const ms = Date.now() - started
    await page.waitForTimeout(1200)
    const m = await page.evaluate(() => {
      const main = document.querySelector('.layout-page-content') || document.body
      const vis = el => !!(el.offsetWidth || el.offsetHeight)
      const q = s => [...main.querySelectorAll(s)].filter(vis)
      return {
        finalPath: location.pathname + location.search,
        h: q('h1,h2,h3,h4,h5,h6').slice(0, 6).map(e => e.tagName + ':' + e.textContent.trim().slice(0, 50)),
        tabs: q('.v-tab').map(e => e.textContent.trim().slice(0, 28)),
        buttons: q('button.v-btn:not(.v-btn--icon), a.v-btn:not(.v-btn--icon)').map(e => e.textContent.trim().slice(0, 30)).filter(Boolean).slice(0, 25),
        iconButtons: q('.v-btn--icon').length,
        inputs: q('input:not([type=hidden]), textarea, .v-select').length,
        tables: q('table').length,
        cards: q('.v-card').length,
        chips: q('.v-chip').length,
        alerts: q('.v-alert').map(e => e.textContent.trim().slice(0, 90)),
        charts: q('.apexcharts-canvas').length,
        scrollH: document.documentElement.scrollHeight,
        empty: /aucun|no data|nothing/i.test(main.innerText.slice(0, 3000)),
        words: main.innerText.split(/\s+/).length,
      }
    })
    n++
    const shot = `${String(n).padStart(2, '0')}-${TAG}-${t.path.replace(/[^a-z0-9]+/gi, '_').slice(1, 50)}.jpg`
    await page.screenshot({ path: `${OUT}/${shot}`, type: 'jpeg', quality: 55, fullPage: false })
    surfaces.push({ group: t.group, depth: t.depth, title: t.title, path: t.path, ms, spa: pushed, shot, ...m })
  }
  fs.writeFileSync(`${OUT}/surfaces-${TAG}.json`, JSON.stringify({ profile: PROFILE, landing, surfaces, errors }, null, 1))
})
