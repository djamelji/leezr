// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'
const S = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/sec-shots'
fs.mkdirSync(S, { recursive: true })
const EMP = 1236

test('SECURITY-1 — suspend UI + live enforcement loop (local fixture)', async ({ browser }) => {
  test.setTimeout(3 * 60 * 1000)

  // 1) Owner: fiche → access dialog (lifecycle UI) + suspend via authenticated API.
  const ownerCtx = await browser.newContext()
  const owner = await ownerCtx.newPage(); owner.setDefaultTimeout(20000)
  await loginCompany(owner, 'sec-owner@test.test', 'password')
  await owner.goto(`/company/workforce/employees/${EMP}`, { waitUntil: 'domcontentloaded' })
  await owner.waitForTimeout(2500)
  await owner.getByText(/gérer l.?accès/i).first().click().catch(() => {})
  await owner.waitForTimeout(1500)
  await owner.screenshot({ path: `${S}/01-access-dialog-lifecycle.png` }).catch(() => {})
  // 2) Member session established FIRST (navigate to company → sets X-Company-Id cookie + proves access).
  const memberCtx = await browser.newContext()
  const member = await memberCtx.newPage(); member.setDefaultTimeout(20000)
  await loginCompany(member, 'sec-member@test.test', 'password')
  await memberCtx.addCookies([{ name: 'currentCompanyId', value: '22', url: 'https://leezr.test' }])
  await member.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await member.waitForTimeout(2000)
  const before = await apiRequest(member, { method: 'GET', path: '/api/nav' })
  console.log('member /nav BEFORE suspend →', before.status)
  expect(before.status).toBe(200)

  // Owner suspends.
  const suspend = await apiRequest(owner, { method: 'POST', path: `/api/workforce/employees/${EMP}/access/suspend`, body: { reason: 'offboarding' } })
  console.log('owner suspend →', suspend.status, 'state:', suspend.json?.state)

  // Member: access BLOCKED server-side (same session, effective immediately).
  const blocked = await apiRequest(member, { method: 'GET', path: '/api/nav' })
  console.log('member /nav after suspend →', blocked.status, blocked.json?.code)
  expect(blocked.status).toBe(403)

  // 3) Owner restores.
  const restore = await apiRequest(owner, { method: 'POST', path: `/api/workforce/employees/${EMP}/access/restore` })
  console.log('owner restore →', restore.status, 'state:', restore.json?.state)

  // 4) Member access works again.
  const ok = await apiRequest(member, { method: 'GET', path: '/api/nav' })
  console.log('member /nav after restore →', ok.status)
  expect(ok.status).toBe(200)

  await ownerCtx.close(); await memberCtx.close()
})
