// @ts-check
// Leezr 4.2 M8 (ADR-845) — end of relationship, browser recipe on the MARKED clone, fictitious company 106 only.
// Fixture : scripts/audit842-m8-fixture.php (reset | expire). The downloaded archive is REALLY opened (unzip) and
// its files are read : manifest, CSV content, absence of secrets and of other companies' data.
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M8 recipe only (AUDIT_PASSWORD_FILE)')

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m8'
const OWNER = 'owner.at4f8@audit842.test'
const env = { ...process.env, DB_DATABASE: process.env.E2E_DB_DATABASE || 'leezr_audit842', CACHE_PREFIX: 'leezr_audit842-', REDIS_PREFIX: 'leezr_audit842-' }
const fixture = mode => execFileSync('php', ['scripts/audit842-m8-fixture.php', mode], { env }).toString()
const shot = async (page, name) => { await page.waitForTimeout(500); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60, fullPage: true }) }

function watch(page, allowed = []) {
  const problems = []

  page.on('pageerror', e => problems.push(`js ${e.message}`))
  page.on('response', r => {
    const u = r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')
    if (r.status() >= 400 && u.startsWith('/api/') && !u.includes('/realtime/') && !(r.status() === 429 && u.startsWith('/api/public/')) && !allowed.some(a => `${r.status()} ${u}`.match(a)))
      problems.push(`${r.status()} ${r.request().method()} ${u}`)
  })

  return problems
}

const openClosure = async page => {
  await page.goto('/company/billing/closure', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="company-closure"]')).toBeVisible()
}

test.beforeAll(() => { fs.mkdirSync(OUT, { recursive: true }); fixture('reset') })

test('Company : comprendre, exporter (archive vérifiée), expiration, révocation Platform, résiliation et annulation', async ({ page, browser }) => {
  test.setTimeout(6 * 60 * 1000)
  page.setDefaultTimeout(20000)

  const problems = watch(page, [/^422 \/api\/company\/data-exports$/, /^410 \/api\/company\/data-exports\/\d+\/download$/])

  await loginCompany(page, OWNER, PASSWORD)
  await openClosure(page)
  await expect(page.locator('[data-test="closure-state"]')).toHaveText('Actif')
  await expect(page.locator('[data-test="closure-effects"]')).toContainText('Vos données ne sont pas supprimées')
  await expect(page.locator('[data-test="closure-effects"]')).toContainText("n'est pas encore définie pour votre pays")
  await expect(page.locator('[data-test="closure-obligations"]')).toContainText('Nous vous conseillons de télécharger un export complet')
  await shot(page, 'A1-page-fin-de-contrat')

  // reinforced identity check : wrong password refused, right password → archive built
  await page.locator('[data-test="closure-export-request"]').click()
  await page.locator('[data-test="closure-export-password"] input').fill('mauvais-mot-de-passe')
  await page.locator('[data-test="closure-export-confirm"]').click()
  await expect(page.locator('[data-test="closure-export-password"]')).toContainText('Mot de passe incorrect')
  await page.locator('[data-test="closure-export-password"] input').fill(PASSWORD)
  await page.locator('[data-test="closure-export-confirm"]').click()
  await expect(page.locator('[data-test="closure-message"]')).toContainText('en préparation')

  const row = page.locator('[data-test^="closure-export-status-"]').first()

  await expect(row).toHaveText('Export complet')

  const exportId = (await row.getAttribute('data-test')).replace('closure-export-status-', '')

  await shot(page, 'A2-export-pret')

  // real download, real archive
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator(`[data-test="closure-export-download-${exportId}"]`).click()])
  const zipPath = path.join(OUT, 'exemple-export.zip')

  fs.copyFileSync(await dl.path(), zipPath)

  const list = execFileSync('unzip', ['-Z1', zipPath]).toString().trim().split('\n')
  for (const f of ['manifest.json', 'company.json', 'members.csv', 'workforce/employees.csv', 'workforce/time/entries_2026.csv', 'billing/subscriptions.csv', 'audit/company_audit_log.csv', 'documents/index.csv'])
    expect(list, f).toContain(f)
  const read = f => execFileSync('unzip', ['-p', zipPath, f]).toString('utf8')
  const manifest = JSON.parse(read('manifest.json'))

  expect(manifest.company.id).toBe(106)
  expect(manifest.files.find(f => f.path === 'workforce/time/entries_2026.csv').rows).toBeGreaterThanOrEqual(2)
  expect(manifest.exclusions.length).toBeGreaterThanOrEqual(5)

  const employees = read('workforce/employees.csv')

  expect(employees.startsWith('﻿Matricule;Nom;Prénom')).toBe(true)
  expect(employees).toContain('M7-A;Export;Alice')
  expect(read('workforce/time/entries_2026.csv')).toContain('2026-03-10 09:00') // M7 export reused, Paris time
  expect(read('members.csv')).toContain(OWNER)

  const everything = list.filter(f => !f.endsWith('/')).map(read).join('\n')

  expect(everything).not.toMatch(/\$2y\$|remember_token|two_factor_secret/)
  expect(everything, 'no data of the other fictitious company').not.toContain('camille.recette834')
  fs.writeFileSync(path.join(OUT, 'exemple-manifest.json'), JSON.stringify(manifest, null, 2))
  await expect(page.locator(`[data-test="closure-export-${exportId}"]`)).toBeVisible()

  // one export at a time / cooldown : refused with an explanation
  await page.locator('[data-test="closure-export-request"]').click()
  await page.locator('[data-test="closure-export-password"] input').fill(PASSWORD)
  await page.locator('[data-test="closure-export-confirm"]').click()
  await expect(page.locator('[data-test="closure-export-password"]')).toContainText('vous pourrez en demander un nouveau')
  await page.keyboard.press('Escape')

  // expiry : the link is refused, then the scheduled prune removes the FILE and marks the export expired
  fixture('expire')
  await page.locator(`[data-test="closure-export-download-${exportId}"]`).click()
  await expect(page.locator('[data-test="closure-message"]')).toContainText('expiré ou a été révoqué')
  execFileSync('php', ['artisan', 'company-exports:prune'], { env })
  await openClosure(page)
  await expect(page.locator(`[data-test="closure-export-status-${exportId}"]`)).toHaveText('Expiré')
  await shot(page, 'A3-export-expire')

  // a second export, revoked from Platform
  await page.locator('[data-test="closure-export-request"]').click()
  await page.locator('[data-test="closure-export-password"] input').fill(PASSWORD)
  await page.locator('[data-test="closure-export-confirm"]').click()
  await expect(page.locator('[data-test^="closure-export-status-"]').first()).toHaveText('Export complet')

  const second = (await page.locator('[data-test^="closure-export-status-"]').first().getAttribute('data-test')).replace('closure-export-status-', '')

  const ctx = await browser.newContext()
  const op = await ctx.newPage()

  op.setDefaultTimeout(20000)

  const opProblems = watch(op)

  await loginPlatform(op, 'admin@leezr.com', PASSWORD)
  await op.goto('/platform/companies/106/billing', { waitUntil: 'networkidle' })

  const card = op.locator('[data-testid="company-lifecycle"]')

  await expect(card).toBeVisible()
  await expect(card.locator('[data-testid="lifecycle-commercial"]')).toContainText('Actif')
  await expect(card.locator(`[data-testid="lifecycle-export-${exportId}"]`)).toContainText('Expiré')
  await card.scrollIntoViewIfNeeded()
  await op.screenshot({ path: `${OUT}/apres-B1-platform-supervision.jpg`, type: 'jpeg', quality: 60 })
  await card.locator(`[data-testid="lifecycle-revoke-${second}"]`).click()
  await expect(card.locator(`[data-testid="lifecycle-export-${second}"]`)).toContainText('Révoqué')
  await op.screenshot({ path: `${OUT}/apres-B2-platform-revocation.jpg`, type: 'jpeg', quality: 60 })
  expect(opProblems).toEqual([])
  await ctx.close()

  await openClosure(page)
  await expect(page.locator(`[data-test="closure-export-status-${second}"]`)).toHaveText('Révoqué')
  await expect(page.locator(`[data-test="closure-export-download-${second}"]`)).toHaveCount(0)

  // scheduled cancellation, then undone — access kept throughout
  await page.locator('[data-test="closure-cancel"]').click()
  await page.locator('.v-overlay--active .v-card-actions button').last().click()
  await expect(page.locator('[data-test="closure-state"]')).toHaveText('Résiliation programmée')
  await expect(page.locator('[data-test="closure-undo-cancel"]')).toBeVisible()
  await shot(page, 'A4-resiliation-programmee')
  await page.locator('[data-test="closure-undo-cancel"]').click()
  await expect(page.locator('[data-test="closure-state"]')).toHaveText('Actif')
  await expect(page.locator('[data-test="closure-message"]')).toContainText('La résiliation est annulée')
  await shot(page, 'A5-resiliation-annulee')
  expect(problems).toEqual([])
})

test('Sans droit Facturation ni propriété : situation et export refusés', async ({ page }) => {
  page.setDefaultTimeout(20000)
  await loginCompany(page, 'chef844@audit842.test', PASSWORD)

  const r = await page.evaluate(async () => {
    const h = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Company-Id': '106' }
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
    const res = await fetch('/api/company/lifecycle', { headers: h, credentials: 'include' })
    const life = await res.json()
    const post = await fetch('/api/company/data-exports', { method: 'POST', headers: { ...h, 'X-XSRF-TOKEN': xsrf }, credentials: 'include', body: JSON.stringify({ password: 'x' }) })

    return { status: res.status, post: post.status, msg: life.message }
  })


  // no billing right : the whole billing group is refused (an administrator WITH billing rights but not owner gets
  // can_export=false and 403 on the request — covered by Adr845CompanyLifecycleTest)
  expect(r).toEqual({ status: 403, post: 403, msg: 'Permission required: billing.manage' })
})
