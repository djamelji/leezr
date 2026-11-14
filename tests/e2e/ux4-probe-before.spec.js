// @ts-check
// ADR-779 (UX4) — sonde AVANT : structure, débordement et vocabulaire des écrans Planning & temps (lecture seule).
import fs from 'node:fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/ux4-779'
const probe = page => page.evaluate(() => {
  const vw = document.documentElement.clientWidth
  const over = [...document.querySelectorAll('main *')].filter(el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.right > vw + 2 && getComputedStyle(el).position !== 'fixed' }).length
  return {
    path: location.pathname, vw, scrollW: document.documentElement.scrollWidth, overflowing: over,
    buttons: [...document.querySelectorAll('main button, main a.v-btn')].filter(b => b.offsetParent).length,
    tabs: [...document.querySelectorAll('main .v-tab')].map(t => t.textContent.trim()).filter(Boolean),
    h4: [...document.querySelectorAll('main h4, main h3')].slice(0, 3).map(h => h.textContent.trim()),
    tech: (document.body.innerText.match(/centi[eè]mes?|\(minutes\)|\(centimes\)/gi) || []).length,
  }
})
const go = async (page, p) => { await page.goto(p, { waitUntil: 'networkidle' }); await page.waitForTimeout(1500) }

for (const [label, vp] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
  test(`${process.env.UX4_PHASE || 'before'} ${label}`, async ({ page }) => {
    test.setTimeout(180000)
    await page.setViewportSize(vp)
    await loginCompany(page, 'owner@leezr.test')
    const res = {}
    for (const [k, p] of [['planning', '/company/workforce/planning'], ['time', '/company/workforce/time'], ['leave', '/company/workforce/leave'], ['me', '/company/workforce/me']]) {
      await go(page, p)
      res[k] = await probe(page)
      await page.screenshot({ path: `${OUT}/${process.env.UX4_PHASE || 'before'}-${label}-${k}.jpg`, type: 'jpeg', quality: 50 })
    }
    fs.writeFileSync(`${OUT}/${process.env.UX4_PHASE || 'before'}-${label}.json`, JSON.stringify(res, null, 2))
  })
}
