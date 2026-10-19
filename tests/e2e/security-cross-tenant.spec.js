// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

// Multi-tenant isolation (LOT 1): a user authenticated for Company A cannot reach Company B's
// resources by swapping the X-Company-Id header. SetCompanyContext enforces membership (403),
// so no cross-tenant data is ever disclosed. owner@leezr.test is a member of company 1 only.

// Raw fetch letting us choose the X-Company-Id header (what a malicious client would tamper with).
async function statusForCompany(page, path, companyId) {
  return page.evaluate(async ({ p, cid }) => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const res = await fetch(p, {
      headers: {
        'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''),
        'X-Company-Id': String(cid),
        Accept: 'application/json',
      },
      credentials: 'include',
    })
    let body = ''
    try { body = await res.text() } catch { /* ignore */ }

    return { status: res.status, body: body.slice(0, 500) }
  }, { p: path, cid: companyId })
}

const OWN = 1
const OTHER = 2 // Trial Logistics — owner@leezr.test is NOT a member

test('a company user cannot access another company by tampering X-Company-Id', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/dashboard', { waitUntil: 'networkidle' })
  await page.waitForTimeout(600)

  // Positive control: own company resources are reachable.
  const own = await statusForCompany(page, '/api/workforce/employees', OWN)
  expect(own.status, 'own company employees reachable').toBe(200)

  // Cross-tenant attempts → 403, and the body must NOT leak the other tenant's data.
  for (const path of ['/api/workforce/employees', '/api/company/members', '/api/shipments']) {
    const cross = await statusForCompany(page, path, OTHER)
    expect(cross.status, `cross-tenant ${path} refused`).toBe(403)
    // No disclosure: the 403 body should not contain other-tenant records (no employee/member arrays).
    expect(/"data"\s*:\s*\[\s*\{/.test(cross.body), `no data array leaked for ${path}`).toBe(false)
  }
})
