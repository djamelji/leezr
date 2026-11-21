// @ts-check
// Leezr 4.2 — AUDIT : non-destructive isolation probes (GET only, marked clone only — global-setup, ADR-831).
// A member of company 22 / 3 tries to reach data of company 1 or 5 by manipulating ids or the company header ;
// a company session tries Platform APIs ; a driver tries management APIs. Expected : refusal (401/403/404).
import fs from 'node:fs'
import { test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

// audit campaign only : skipped by a normal E2E run
test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 audit campaign only (AUDIT_PASSWORD_FILE)')
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/data/isolation.json'
const results = []

const probe = async (page, who, label, path, headers = {}) => {
  const r = await page.evaluate(async ({ p, h }) => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const res = await fetch(p, { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''), 'X-Company-Id': h.company ?? decodeURIComponent(get('currentCompanyId') || '') }, credentials: 'include' })
    let body = ''
    try { body = JSON.stringify(await res.json()).slice(0, 160) } catch { /* no body */ }

    return { status: res.status, body }
  }, { p: path, h: headers })
  const refused = [401, 403, 404, 422].includes(r.status)
  results.push({ who, label, path, header: headers.company ?? null, status: r.status, refused, body: refused ? '' : '[contenu masqué]' })
  fs.writeFileSync(OUT, JSON.stringify(results, null, 1))
}

test('isolation probes', async ({ page }) => {
  test.setTimeout(6 * 60 * 1000)
  // member of company 22 (limited) → company 1 data
  await loginCompany(page, 'sec-member@test.test', PASSWORD)
  await probe(page, 'membre société 22', 'en-tête X-Company-Id = 1 (non membre)', '/api/company', { company: '1' })
  await probe(page, 'membre société 22', 'liste des personnes de la société 1', '/api/company/people', { company: '1' })
  await probe(page, 'membre société 22', 'salarié 1 (société 1) via sa propre société', '/api/workforce/employees/1/file')
  await probe(page, 'membre société 22', 'membre 3 (société 1)', '/api/company/members/3')
  await probe(page, 'membre société 22', 'expédition 1 (société 1)', '/api/shipments/1')
  await probe(page, 'membre société 22', 'facture 1 (société 1)', '/api/billing/invoices/1')
  await probe(page, 'membre société 22', 'ticket 1 (société 1)', '/api/support/tickets/1')
  await probe(page, 'membre société 22', 'API Platform avec session Company', '/api/platform/companies')
  await probe(page, 'membre société 22', 'gestion des rôles sans droit', '/api/company/roles')
  await page.context().clearCookies()

  // owner of company 3 → company 1 / 5
  await loginCompany(page, 'pending@leezr.test', PASSWORD)
  await probe(page, 'propriétaire société 3', 'salarié 13 (société 5)', '/api/workforce/employees/13/file')
  await probe(page, 'propriétaire société 3', 'membre 3 (société 1)', '/api/company/members/3')
  await probe(page, 'propriétaire société 3', 'document membre 1 (société 1)', '/api/company/members/3/documents')
  await probe(page, 'propriétaire société 3', 'en-tête X-Company-Id = 5', '/api/company/people', { company: '5' })
  await page.context().clearCookies()

  // driver of company 1 → management APIs of its own company
  await loginCompany(page, 'bob@leezr.test', PASSWORD)
  await probe(page, 'chauffeur société 1', 'liste des membres', '/api/company/members')
  await probe(page, 'chauffeur société 1', 'rôles', '/api/company/roles')
  await probe(page, 'chauffeur société 1', 'facturation', '/api/billing/subscription')
  await probe(page, 'chauffeur société 1', 'export salariés', '/api/workforce/employees/export')
  await probe(page, 'chauffeur société 1', 'journal d\'audit', '/api/audit')
  await probe(page, 'chauffeur société 1', 'paie', '/api/workforce/payroll')
})
