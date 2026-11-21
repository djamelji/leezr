// @ts-check
// Leezr 4.2 — AUDIT (read-only navigation). Logs in as ONE profile, walks every menu entry and every page reachable
// by links (detail pages discovered from lists, one per route pattern), clicks every tab, and measures : API errors,
// JS/console errors, headings, actions, empty states, horizontal overflow (desktop + 390 px), axe (serious/critical).
// Only links and tabs are clicked — never a button that writes. Marked clone only (global-setup, ADR-831).
//   AUDIT_SCOPE=platform|company AUDIT_USER=… AUDIT_TAG=… AUDIT_PASSWORD_FILE=… [AUDIT_COMPANY_ID=…] [AUDIT_EXTRA=/a,/b]
import fs from 'node:fs'
import { test } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { apiRequest, loginCompany, loginPlatform } from './helpers.js'

const OUT = process.env.AUDIT_OUT || 'docs/bmad/leezr-4-2-audit/data/crawl'
const SCOPE = process.env.AUDIT_SCOPE || 'company'
const USER = process.env.AUDIT_USER
const TAG = process.env.AUDIT_TAG || USER
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const EXTRA = (process.env.AUDIT_EXTRA || '').split(',').filter(Boolean)
const MAX = Number(process.env.AUDIT_MAX || 140)

// every step is bounded : a hanging page is recorded as such, never blocks the campaign
const within = (promise, ms, fallback = null) => Promise.race([promise.catch(() => fallback), new Promise(r => setTimeout(() => r(fallback), ms))])

test.skip(!process.env.AUDIT_SCOPE, 'Leezr 4.2 audit campaign only (AUDIT_SCOPE)')

const pattern = p => p.split('?')[0].replace(/\/\d+(?=\/|$)/g, '/:id').replace(/\/[0-9a-f-]{20,}(?=\/|$)/gi, '/:uuid')
  .replace(/\/(modules|plans|markets|documentation|help-center)\/[^/]+/, '/$1/:key')
const slug = p => p.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '_').slice(0, 80) || 'root'

const flatten = (items, group = null, out = []) => {
  for (const it of items || []) {
    const title = it.title || it.heading || it.key
    if ((it.to && Object.keys(it.to).length) || typeof it.to === 'string')
      out.push({ group, title, key: it.key, to: it.to })
    if (it.children)
      flatten(it.children, group || title, out)
  }

  return out
}

test(`audit842 crawl — ${SCOPE} — ${TAG}`, async ({ page }) => {
  test.setTimeout(60 * 60 * 1000)
  fs.mkdirSync(`${OUT}/shots/${TAG}`, { recursive: true })
  fs.rmSync(`${OUT}/crawl-${TAG}.jsonl`, { force: true })
  const events = []
  let current = null
  page.on('response', r => {
    if (r.status() >= 400 && r.url().includes('/api/'))
      events.push({ page: current, kind: 'api', status: r.status(), method: r.request().method(), url: r.url().replace(/^https?:\/\/[^/]+/, '').slice(0, 160) })
  })
  page.on('pageerror', e => events.push({ page: current, kind: 'js', text: String(e.message).slice(0, 220) }))
  page.on('console', m => {
    if (m.type() === 'error' && !/ERR_NETWORK_CHANGED|Failed to load resource/.test(m.text()))
      events.push({ page: current, kind: 'console', text: m.text().slice(0, 220) })
    if (m.type() === 'warning' && /\[Vue warn\]|\[intlify\]/.test(m.text()))
      events.push({ page: current, kind: 'warn', text: m.text().slice(0, 220) })
  })

  if (SCOPE === 'platform')
    await loginPlatform(page, USER, PASSWORD)
  else if (SCOPE === 'public')
    await page.goto('/login', { waitUntil: 'networkidle' }) // unauthenticated visitor
  else
    await loginCompany(page, USER, PASSWORD)
  if (process.env.AUDIT_COMPANY_ID) {
    await page.context().addCookies([{ name: 'currentCompanyId', value: process.env.AUDIT_COMPANY_ID, url: page.url() }])
    await page.reload({ waitUntil: 'networkidle' })
  }
  const landing = new URL(page.url()).pathname + new URL(page.url()).search

  const nav = SCOPE === 'public' ? { json: {} } : await apiRequest(page, { path: SCOPE === 'platform' ? '/api/platform/nav' : '/api/nav' })
  fs.writeFileSync(`${OUT}/nav-${TAG}.json`, JSON.stringify(nav.json, null, 1))
  const groups = nav.json?.groups || nav.json?.items || []
  const entries = (Array.isArray(groups) ? groups : []).flatMap(g => flatten(g.items || [g], g.title || g.key))
  for (const f of nav.json?.footer_links || []) if (f.to) entries.push({ group: 'footer', title: f.title || f.key, key: f.key, to: f.to })
  const navPaths = await page.evaluate(list => {
    const router = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router

    return list.map(e => { try { return typeof e.to === 'string' ? e.to : router.resolve(e.to).fullPath } catch { return null } })
  }, entries)

  const queue = [{ path: landing, source: 'landing', group: null, title: 'landing' }]
  entries.forEach((e, i) => queue.push({ path: navPaths[i], source: 'menu', group: e.group, title: e.title, key: e.key }))
  EXTRA.forEach(p => queue.push({ path: p, source: 'extra', title: p }))
  const seen = new Set()
  const pages = []

  const go = async path => {
    current = path
    const started = Date.now()
    const pushed = await within(page.evaluate(async p => {
      const router = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router
      if (!router)
        return false
      router.push(p).catch(() => {})

      return true
    }, path), 8000, false)
    if (!pushed)
      await within(page.goto(path, { waitUntil: 'domcontentloaded' }), 20000)
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
    await page.waitForFunction(() => (document.querySelector('.layout-page-content')?.innerText || '').trim().length > 20, null, { timeout: 15000 }).catch(() => {})
    await page.waitForTimeout(900)

    return Date.now() - started
  }

  const measure = () => page.evaluate(() => {
    const main = document.querySelector('.layout-page-content') || document.body
    const vis = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length)
    const q = s => [...main.querySelectorAll(s)].filter(vis)
    const text = main.innerText || ''

    return {
      finalPath: location.pathname + location.search,
      title: document.title,
      headings: q('h1,h2,h3,h4,h5').slice(0, 8).map(e => e.tagName + ':' + e.textContent.trim().replace(/\s+/g, ' ').slice(0, 70)),
      tabs: q('.v-tab').map(e => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 40)),
      buttons: q('button.v-btn:not(.v-btn--icon), a.v-btn:not(.v-btn--icon)').map(e => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 40)).filter(Boolean).slice(0, 30),
      iconButtonsWithoutLabel: q('.v-btn--icon').filter(b => !b.getAttribute('aria-label') && !b.getAttribute('title') && !b.textContent.trim()).length,
      inputs: q('input:not([type=hidden]), textarea, .v-select').length,
      tables: q('table').length,
      rows: q('tbody tr').length,
      pagination: q('.v-pagination, .v-data-table-footer').length,
      alerts: q('.v-alert').map(e => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 140)),
      empty: /aucun|aucune|no data|nothing to|pas encore|vide/i.test(text.slice(0, 4000)),
      forbidden: /403|accès refusé|access denied|not authorized|non autoris/i.test(text.slice(0, 1500)),
      rawKeys: (text.match(/\b[a-z][a-zA-Z]+(\.[a-zA-Z_]+){2,}\b/g) || []).filter(k => !/@|\.(com|fr|test|invalid|leezr)\b/.test(k)).slice(0, 8),
      englishHints: (text.match(/\b(Save|Cancel|Delete|Create|Edit|Search|Loading|No data available|Items per page|Actions|Status|Settings)\b/g) || []).slice(0, 8),
      textLength: text.length,
      overflowX: document.documentElement.scrollWidth > window.innerWidth + 2,
      links: [...main.querySelectorAll('a[href^="/"]')].filter(vis).map(a => a.getAttribute('href')).filter(h => !/\/(logout|login)/.test(h)).slice(0, 80),
    }
  })

  while (queue.length && pages.length < MAX) {
    const item = queue.shift()
    if (!item.path || seen.has(pattern(item.path)))
      continue
    seen.add(pattern(item.path))
    const before = events.length
    console.log(`[audit842] ${pages.length + 1} ${item.path}`)
    const ms = await go(item.path)
    const m = (await within(measure(), 15000)) || { finalPath: null, hung: true, links: [], tabs: [], headings: [] }
    await within(page.screenshot({ path: `${OUT}/shots/${TAG}/${slug(item.path)}.jpg`, type: 'jpeg', quality: 55, fullPage: false }), 15000)

    // click every tab (real clicks), measure what each one loads
    const tabResults = []
    const tabCount = (await within(page.locator('.layout-page-content .v-tab:visible').count(), 5000)) || 0
    for (let i = 0; i < Math.min(tabCount, 14); i++) {
      const tab = page.locator('.layout-page-content .v-tab:visible').nth(i)
      const label = ((await within(tab.textContent(), 3000, '')) || '').trim().replace(/\s+/g, ' ').slice(0, 40)
      const evBefore = events.length
      current = `${item.path} [tab:${label}]`
      await tab.click({ timeout: 5000 }).catch(() => {})
      await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
      await page.waitForTimeout(600)
      const tm = (await within(page.evaluate(() => {
        const main = document.querySelector('.layout-page-content') || document.body

        return { path: location.pathname + location.search, textLength: (main.innerText || '').length, empty: /aucun|aucune|no data|pas encore/i.test((main.innerText || '').slice(0, 3000)) }
      }), 8000)) || { hung: true }
      tabResults.push({ label, ...tm, errors: events.length - evBefore })
      for (const h of (await within(page.evaluate(() => [...(document.querySelector('.layout-page-content') || document.body).querySelectorAll('a[href^="/"]')].map(a => a.getAttribute('href')).slice(0, 60)), 5000)) || [])
        m.links.push(h)
    }

    let axe = null
    if (item.source === 'menu' || item.source === 'landing') {
      try {
        const r = await within(new AxeBuilder({ page }).include('.layout-page-content').withTags(['wcag2a', 'wcag2aa']).analyze(), 25000)
        axe = r === null ? 'timeout' : r.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.length }))
      }
      catch { axe = 'n/a' }
    }

    pages.push({ ...item, pattern: pattern(item.path), ms, ...m, links: undefined, tabs: m.tabs, tabResults, axe, errors: events.slice(before) })
    fs.appendFileSync(`${OUT}/crawl-${TAG}.jsonl`, JSON.stringify(pages[pages.length - 1]) + '\n')
    for (const href of new Set(m.links)) {
      const p = href.split('#')[0]
      if (!seen.has(pattern(p)) && !queue.some(q => q.path && pattern(q.path) === pattern(p)))
        queue.push({ path: p, source: `link:${item.path}`, title: p })
    }
  }

  // responsive pass : menu pages at 390 px
  await page.setViewportSize({ width: 390, height: 844 })
  const mobile = []
  for (const p of pages.filter(x => x.source === 'menu' || x.source === 'landing').slice(0, 45)) {
    await go(p.path)
    const r = (await within(page.evaluate(() => ({ overflowX: document.documentElement.scrollWidth > window.innerWidth + 2, scrollWidth: document.documentElement.scrollWidth })), 8000)) || { hung: true }
    mobile.push({ path: p.path, ...r })
    if (r.overflowX)
      await within(page.screenshot({ path: `${OUT}/shots/${TAG}/m_${slug(p.path)}.jpg`, type: "jpeg", quality: 50 }), 10000)
  }

  fs.writeFileSync(`${OUT}/crawl-${TAG}.json`, JSON.stringify({ scope: SCOPE, user: USER, tag: TAG, landing, navEntries: entries.length, pages, mobile, queueLeft: queue.length }, null, 1))
})
