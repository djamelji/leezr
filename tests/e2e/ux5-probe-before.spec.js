// @ts-check
// ADR-781 (UX5) — sonde AVANT/APRÈS des réglages (lecture seule) : entrées, onglets, champs, débordement.
import fs from 'node:fs'
import { test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

const OUT = 'docs/bmad/ux5-781'
const PHASE = process.env.UX5_PHASE || 'before'
const probe = page => page.evaluate(() => {
  const main = document.querySelector('main') || document.body
  const vis = el => el.offsetParent !== null
  return {
    path: location.pathname,
    scrollW: document.documentElement.scrollWidth,
    tabs: [...main.querySelectorAll('.v-tab')].filter(vis).map(t => t.textContent.trim()).filter(Boolean),
    fields: [...main.querySelectorAll('input:not([type=hidden]), textarea, .v-select')].filter(vis).length,
    buttons: [...main.querySelectorAll('button, a.v-btn')].filter(vis).length,
    tech: (main.innerText.match(/\b(slug|code|key|ID|bps|centimes|cents|webhook|JSON|tenant|module_key)\b/g) || []).slice(0, 12),
    h: [...main.querySelectorAll('h1,h2,h4,h5')].slice(0, 4).map(h => h.textContent.trim()),
  }
})
const flat = items => items.flatMap(i => [i, ...flat(i.children || []), ...flat(i.hubItems || [])])

test(`${PHASE} réglages propriétaire`, async ({ page }) => {
  test.setTimeout(240000)
  await loginCompany(page, 'owner@leezr.test')
  const nav = (await apiRequest(page, { path: '/api/nav' })).json
  const settings = (nav.groups || []).find(g => g.key === 'settings')
  const entries = flat(settings?.items || []).filter(i => i.to && i.to.name)
  const res = { navEntries: entries.map(e => ({ key: e.key, to: e.to })), pages: {} }
  for (const vp of [['desktop', 1440, 900], ['mobile', 390, 844]]) {
    await page.setViewportSize({ width: vp[1], height: vp[2] })
    for (const e of entries) {
      const url = await page.evaluate(to => document.querySelector('#app').__vue_app__.config.globalProperties.$router.resolve(to).href, e.to)
      await page.goto(url, { waitUntil: 'networkidle' })
      await page.waitForTimeout(1200)
      res.pages[`${vp[0]}:${e.key}`] = await probe(page)
      await page.screenshot({ path: `${OUT}/${PHASE}-${vp[0]}-${e.key}.jpg`, type: 'jpeg', quality: 45 })
    }
  }
  fs.writeFileSync(`${OUT}/${PHASE}-owner.json`, JSON.stringify(res, null, 1))
})
