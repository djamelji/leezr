// @ts-check
// Leezr 5.0 L7 closure (ADR-862) — visual review gallery on the MARKED clone. Same pages, same accounts, same data,
// same viewport for « avant » (Leezr 4.2, commit 753d5372 served from a worktree) and « après » (current build).
//   GALLERY_SET=avant|apres  GALLERY_ONLY=<regexp on id>  (AUDIT_PASSWORD_FILE + E2E_* as every recipe)
// Viewport screenshots only (identical dimensions) : desktop 1440×900, mobile 390×844, light and dark.
// Read only : the pages are opened, nothing is clicked that writes.
import fs from 'node:fs'
import { test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers.js'

test.skip(!process.env.AUDIT_PASSWORD_FILE || !process.env.GALLERY_SET, 'gallery only (AUDIT_PASSWORD_FILE + GALLERY_SET)')
test.use({ locale: 'fr-FR' })

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const SET = process.env.GALLERY_SET || 'apres'
const ONLY = process.env.GALLERY_ONLY ? new RegExp(process.env.GALLERY_ONLY) : null
const OUT = `docs/bmad/leezr-5-design/captures/gallery/${SET}`

/** the gallery : space, family, id, path (before = same path unless `before` is given) */
export const PAGES = [
  ['platform', 'Dashboard V35', 'p-dashboard', '/platform/dashboard/overview'],
  ['platform', 'Entreprises', 'p-companies', '/platform/companies'],
  ['platform', 'Fiche Company', 'p-company', '/platform/companies/1'],
  ['platform', 'JobDomains', 'p-jobdomains', '/platform/catalog/jobdomains'],
  ['platform', 'JobDomains', 'p-jobdomain', '/platform/jobdomains/1'],
  ['platform', 'Modules', 'p-modules', '/platform/catalog/modules'],
  ['platform', 'Modules', 'p-module', '/platform/modules/logistics_fleet'],
  ['platform', 'Facturation', 'p-billing', '/platform/billing/overview'],
  ['platform', 'Accès Platform', 'p-access', '/platform/access/users'],
  ['platform', 'Exploitation', 'p-operations', '/platform/operations/health'],
  ['platform', 'Catalogue', 'p-catalog', '/platform/catalog/plans'],
  ['company', 'Dashboard V22', 'c-dashboard', '/dashboard'],
  ['company', 'Personnes', 'c-people', '/company/people'],
  ['company', 'Fiche collaborateur', 'c-person', '/company/people/user-3'],
  ['company', 'Planning', 'c-planning', '/company/workforce/planning'],
  ['company', 'Pointage', 'c-time', '/company/workforce/time'],
  ['company', 'Congés', 'c-leave', '/company/workforce/leave'],
  ['company', 'Documents', 'c-documents', '/company/documents/todo'],
  ['company', 'Documents', 'c-documents-compliance', '/company/documents/compliance'],
  ['company', 'Documents', 'c-documents-templates', '/company/documents/templates'],
  ['company', 'Documents', 'c-documents-signatures', '/company/documents/signatures'],
  ['company', 'Préparation de la paie', 'c-payroll', '/company/workforce/payroll'],
  ['company', 'Expéditions', 'c-shipments', '/company/shipments'],
].map(([space, family, id, path, before]) => ({ space, family, id, path: SET === 'avant' && before ? before : path }))

const VARIANTS = [
  { key: 'desktop-clair', width: 1440, height: 900, theme: 'light' },
  { key: 'mobile-clair', width: 390, height: 844, theme: 'light' },
  { key: 'desktop-sombre', width: 1440, height: 900, theme: 'dark' },
  { key: 'mobile-sombre', width: 390, height: 844, theme: 'dark', only: /dashboard|people$|planning|documents$/ },
]

for (const space of ['platform', 'company']) {
  test(`galerie ${SET} · ${space}`, async ({ page, context, baseURL }) => {
    test.setTimeout(20 * 60 * 1000)
    fs.mkdirSync(OUT, { recursive: true })
    await page.setViewportSize({ width: 1440, height: 900 })
    if (space === 'platform') await loginPlatform(page, 'admin@leezr.com', PASSWORD)
    else await loginCompany(page, 'owner@leezr.test', PASSWORD)
    const host = new URL(baseURL || 'http://127.0.0.1:8842').hostname
    const meta = []
    for (const v of VARIANTS) {
      // Vuexy keeps the theme in the `<title>-theme` cookie (themeConfig title is empty → « -theme »)
      await context.addCookies([{ name: '-theme', value: v.theme, domain: host, path: '/' }])
      await page.setViewportSize({ width: v.width, height: v.height })
      for (const p of PAGES.filter(x => x.space === space && (!ONLY || ONLY.test(x.id)) && (!v.only || v.only.test(x.id)))) {
        const res = await page.goto(p.path, { waitUntil: 'networkidle' }).catch(() => null)
        await page.waitForTimeout(1800)
        await page.mouse.move(0, 0)
        const file = `${p.id}--${v.key}.jpg`
        await page.screenshot({ path: `${OUT}/${file}`, type: 'jpeg', quality: 70 })
        meta.push({ ...p, variant: v.key, file, url: new URL(page.url()).pathname, status: res?.status() ?? null, title: await page.title() })
      }
    }
    fs.writeFileSync(`${OUT}/${space}.json`, JSON.stringify(meta, null, 1))
  })
}
