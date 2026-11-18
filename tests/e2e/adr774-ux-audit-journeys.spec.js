// @ts-check
// ADR-774 — AUDIT (lecture seule) : parcours réels. On OUVRE les formulaires et on les mesure, on ne
// soumet RIEN (aucune donnée créée). Script piloté par AUDIT_STEPS (JSON) ; sortie journeys-<tag>.json.
import fs from 'node:fs'
import { test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

const OUT = process.env.AUDIT_OUT || 'docs/bmad/ux-774'
const TAG = process.env.AUDIT_TAG || 'j'
const USER = process.env.AUDIT_USER || 'owner@leezr.test'
const VIEW = (process.env.AUDIT_VIEW || '1440x900').split('x').map(Number)
const DARK = process.env.AUDIT_DARK === '1'
// ADR-830 : parametrised audit TOOL (AUDIT_STEPS=<file>) — skipped in a regular campaign instead of breaking collection
const STEPS = process.env.AUDIT_STEPS ? JSON.parse(fs.readFileSync(process.env.AUDIT_STEPS, 'utf8')) : null
test.skip(!STEPS, 'audit tool : set AUDIT_STEPS to run it')

test.use({ viewport: { width: VIEW[0], height: VIEW[1] }, colorScheme: DARK ? 'dark' : 'light' })

const snapshot = page => page.evaluate(() => {
  const vis = el => !!(el.offsetWidth || el.offsetHeight)
  const overlays = [...document.querySelectorAll('.v-overlay--active .v-overlay__content, .v-navigation-drawer--temporary.v-navigation-drawer--active')].filter(vis)
  const scope = overlays.length ? overlays[overlays.length - 1] : (document.querySelector('.layout-page-content') || document.body)
  const q = s => [...scope.querySelectorAll(s)].filter(vis)
  const fields = q('.v-input').map(i => {
    const label = (i.querySelector('label')?.textContent || i.closest('.app-text-field, .app-select, .app-autocomplete, .app-textarea')?.querySelector('label')?.textContent || i.querySelector('input,textarea')?.getAttribute('placeholder') || '').trim().slice(0, 40)
    const kind = i.className.match(/v-(text-field|select|autocomplete|textarea|checkbox|switch|radio-group|file-input|combobox)/)?.[1] || 'input'
    return `${label || '?'}[${kind}${i.querySelector('[required], [aria-required=true]') || /\*\s*$/.test(label) ? '*' : ''}]`
  })
  return {
    path: location.pathname + location.search,
    overlay: overlays.length ? (scope.querySelector('.v-card-title, h5, h4, .v-toolbar-title')?.textContent || '').trim().slice(0, 60) || 'overlay' : null,
    title: (document.querySelector('.layout-page-content h4, .layout-page-content h5')?.textContent || '').trim().slice(0, 50),
    tabs: q('.v-tab').map(e => e.textContent.trim().slice(0, 26)),
    fields,
    buttons: q('button.v-btn:not(.v-btn--icon), a.v-btn:not(.v-btn--icon)').map(e => e.textContent.trim().slice(0, 32)).filter(Boolean).slice(0, 30),
    menu: q('.v-list-item').map(e => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 60)).slice(0, 14),
    steps: q('.v-stepper-item, .v-stepper-vertical-item').map(e => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 30)),
    alerts: q('.v-alert').map(e => e.textContent.trim().slice(0, 110)),
    hscroll: document.documentElement.scrollWidth > window.innerWidth + 4,
    text: overlays.length ? '' : scope.innerText.replace(/\s+/g, ' ').slice(0, 0),
  }
})

test(`parcours — ${TAG}`, async ({ page }) => {
  test.setTimeout(20 * 60 * 1000)
  const errors = []
  let cur = ''
  page.on('response', r => { if (r.status() >= 400 && r.url().includes('/api/')) errors.push({ at: cur, status: r.status(), method: r.request().method(), url: r.url().replace(/^https?:\/\/[^/]+/, '').slice(0, 120) }) })
  page.on('pageerror', e => errors.push({ at: cur, js: String(e.message).slice(0, 160) }))
  await loginCompany(page, USER)
  if (DARK) { await page.evaluate(() => { for (const c of document.cookie.split('; ')) { const k = c.split('=')[0]; if (/theme$/i.test(k)) document.cookie = `${k}=system; path=/` } }); await page.reload({ waitUntil: 'networkidle' }).catch(() => {}); await page.waitForTimeout(2500) }
  const vars = {}
  const people = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  const rows = people.json?.data || []
  vars.accountless = rows.find(r => r.employee_id && !r.user_id)?.person_ref
  vars.linked = rows.find(r => r.employee_id && r.user_id && !/owner/.test(r.email || ''))?.person_ref
  vars.memberOnly = rows.find(r => !r.employee_id && r.user_id)?.person_ref
  vars.employeeId = rows.find(r => r.employee_id && r.user_id && !/owner/.test(r.email || ''))?.employee_id
  const out = []
  let n = 0
  for (const j of STEPS) {
    const rec = { journey: j.name, steps: [] }
    for (const s of j.steps) {
      cur = `${j.name} › ${s.do}`
      const arg = (s.arg || '').replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `MISSING_${k}`)
      let ok = true
      try {
        if (s.do === 'goto') {
          const pushed = await page.evaluate(async p => { const r = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router; if (!r) return false; await r.push(p).catch(() => {}); return true }, arg).catch(() => false)
          if (!pushed) await page.goto(arg, { waitUntil: 'domcontentloaded' })
          await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
        }
        else if (s.do === 'click') {
          const scope = (await page.locator('.v-overlay--active .v-overlay__content').count()) && !s.page ? page.locator('.v-overlay--active .v-overlay__content').last() : page
          const byTest = arg.startsWith('@') ? scope.locator(`[data-test="${arg.slice(1)}"]`) : null
          const target = byTest || scope.locator('button, a, .v-tab, .v-list-item, [role=button], tr').filter({ hasText: new RegExp(arg, 'i') })
          await target.first().click({ timeout: 6000 })
        }
        else if (s.do === 'css') await page.locator(arg).first().click({ timeout: 6000 })
        else if (s.do === 'esc') await page.keyboard.press('Escape')
        await page.waitForTimeout(s.wait || 1100)
      }
      catch (e) { ok = false }
      const snap = await snapshot(page)
      let shot = null
      if (s.shot) { n++; shot = `j-${TAG}-${String(n).padStart(2, '0')}-${s.shot}.jpg`; await page.screenshot({ path: `${OUT}/${shot}`, type: 'jpeg', quality: 55 }) }
      rec.steps.push({ do: s.do, arg, ok, shot, ...snap })
    }
    out.push(rec)
  }
  fs.writeFileSync(`${OUT}/journeys-${TAG}.json`, JSON.stringify({ user: USER, view: VIEW, vars, journeys: out, errors }, null, 1))
})
