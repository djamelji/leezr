// @ts-check
// ADR-782 (UX6) — captures de revue visuelle (lecture seule). UX6_PAGES=clé,clé ; UX6_PHASE=before|after ;
// UX6_THEMES=light,dark ; UX6_VIEWPORTS=desktop,tablet,mobile. Contrôle aussi le débordement horizontal.
import fs from 'node:fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/ux6-782'
const PAGES = {
  settings: '/company/settings', home: '/dashboard', team: '/company/people', person: '/company/people/employee-1',
  documents: '/company/documents/todo', planning: '/company/workforce/planning', time: '/company/workforce/time',
  leave: '/company/workforce/leave', me: '/company/workforce/me', billing: '/company/billing/overview',
  profile: '/company/profile/overview', org: '/company/workforce/organization/departments', hr: '/company/workforce/settings/general',
  myspace: '/company/my-space/overview',
  modules: '/company/modules', hrtime: '/company/workforce/settings/worktime', hrcontract: '/company/workforce/settings/contract',
  hrsignature: '/company/workforce/settings/signature', doctypes: '/company/documents/settings', leavetypes: '/company/workforce/leave?tab=config',
}
const VP = { desktop: [1440, 900], tablet: [820, 1180], mobile: [390, 844] }
const phase = process.env.UX6_PHASE || 'before'
const keys = (process.env.UX6_PAGES || Object.keys(PAGES).join(',')).split(',')
const themes = (process.env.UX6_THEMES || 'light').split(',')
const vps = (process.env.UX6_VIEWPORTS || 'desktop,mobile').split(',')

test(`capture ${phase}`, async ({ page }) => {
  test.setTimeout(15 * 60 * 1000)
  await loginCompany(page, process.env.UX6_USER || 'owner@leezr.test')
  const report = {}
  for (const theme of themes) {
    await page.evaluate(t => { const s = document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('config'); if (s) s.theme = t }, theme)
    for (const v of vps) {
      await page.setViewportSize({ width: VP[v][0], height: VP[v][1] })
      for (const k of keys) {
        await page.goto(PAGES[k], { waitUntil: 'networkidle' }).catch(() => {})
        await page.waitForTimeout(1400)
        const w = await page.evaluate(() => document.documentElement.scrollWidth)
        const h = await page.evaluate(() => document.documentElement.scrollHeight)
        report[`${phase}:${k}:${v}:${theme}`] = { overflow: w > VP[v][0], height: h }
        await page.screenshot({ path: `${OUT}/${phase}-${k}-${v}-${theme}.jpg`, type: 'jpeg', quality: 60, fullPage: v !== 'desktop' })
      }
    }
  }
  await page.evaluate(() => { const s = document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('config'); if (s) s.theme = 'light' })
  const f = `${OUT}/report-${phase}.json`
  const prev = fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : {}
  fs.writeFileSync(f, JSON.stringify({ ...prev, ...report }, null, 1))
})
