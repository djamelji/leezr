// ADR-786 — recette RBAC navigateur (lecture seule) : pour un profil, chaque page atteignable →
// actions visibles, réponses réseau 4xx/5xx, capture. Aucun clic d'action, aucune écriture.
// Variables : RBAC_USER (défaut bob@leezr.test), RBAC_TAG (préfixe des captures), RBAC_MOBILE=1.
import fs from 'node:fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const USER = process.env.RBAC_USER || 'bob@leezr.test'
const TAG = process.env.RBAC_TAG || 'bob'
const OUT = 'docs/bmad/adr786'
const PAGES = (process.env.RBAC_PAGES || [
  '/dashboard', '/company/workforce/me', '/company/my-space/overview', '/company/my-space/payslips',
  '/company/my-space/documents', '/company/people', '/company/people/user-2', '/company/documents/people',
  '/company/workforce/payroll', '/company/workforce/employees', '/company/workforce/planning', '/company/workforce/leaves',
  '/company/workforce/time', '/company/settings', '/company/modules', '/company/billing/overview', '/company/my-deliveries',
].join(',')).split(',')

test(`audit RBAC — ${TAG}`, async ({ page }) => {
  test.setTimeout(240000)
  if (process.env.RBAC_MOBILE) await page.setViewportSize({ width: 390, height: 844 })
  const report = []
  let current = null
  page.on('response', r => {
    if (current && r.url().includes('/api/') && r.status() >= 400 && !r.url().endsWith('/api/login'))
      current.errors.push(`${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`)
  })
  await loginCompany(page, USER)
  for (const path of PAGES) {
    current = { path, errors: [] }
    await page.goto(path, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(2500) // le flux temps réel empêche « networkidle »
    current.final = new URL(page.url()).pathname
    // Lecture directe : un locator attendrait indéfiniment une page sans titre h1-h5.
    current.title = await page.evaluate(() => document.querySelector('main h1, main h2, main h3, main h4, main h5')?.textContent?.trim() || '')
    current.actions = await page.evaluate(() => [...document.querySelectorAll('main button, main a.v-btn, main .v-btn')]
      .filter(e => e.offsetParent !== null).map(e => e.innerText.trim()).filter(Boolean).filter((t, i, a) => a.indexOf(t) === i).slice(0, 40))
    if (!process.env.RBAC_NOSHOT)
      await page.screenshot({ path: `${OUT}/${TAG}${process.env.RBAC_MOBILE ? '-mobile' : ''}-${path.replace(/\//g, '_').replace(/^_/, '')}.jpg`, fullPage: true })
    report.push(current)
    fs.writeFileSync(`${OUT}/report-${TAG}${process.env.RBAC_MOBILE ? '-mobile' : ''}.json`, JSON.stringify({ user: USER, pages: report }, null, 2))
  }
  current = null
  // Menu latéral
  const nav = await page.evaluate(() => [...document.querySelectorAll('.layout-vertical-nav .nav-link, .layout-vertical-nav .nav-group-label, .layout-vertical-nav li')].map(e => e.innerText.trim().split('\n')[0]).filter(Boolean).filter((t, i, a) => a.indexOf(t) === i))
  fs.writeFileSync(`${OUT}/report-${TAG}${process.env.RBAC_MOBILE ? '-mobile' : ''}.json`, JSON.stringify({ user: USER, nav, pages: report }, null, 2))
})
