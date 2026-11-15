// ADR-788 (application) — livreur : ni invitation ni supervision documentaire ; espace personnel intact.
// Lecture seule : aucune écriture.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const settle = page => page.waitForTimeout(2500)
const OUT = 'docs/bmad/adr788'

test('livreur : espace personnel sans 403, aucune action d\'administration', async ({ page }) => {
  const denied = []
  page.on('response', r => { if (r.status() === 403 && r.url().includes('/api/')) denied.push(`${r.request().method()} ${new URL(r.url()).pathname}`) })
  await loginCompany(page, 'bob@leezr.test')
  for (const [path, shot] of [['/home', 'home'], ['/company/workforce/me', 'me'], ['/company/my-space/documents', 'my-documents'], ['/company/my-space/payslips', 'my-payslips']]) {
    await page.goto(path, { waitUntil: 'domcontentloaded' })
    await settle(page)
    await page.screenshot({ path: `${OUT}/after-bob-${shot}.jpg`, type: 'jpeg', quality: 70 })
  }
  expect(denied, 'aucun 403 parasite sur les pages personnelles').toEqual([])

  await page.goto('/company/people', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.getByText('Ajouter une personne')).toHaveCount(0)
  await page.goto('/company/people/user-2', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('.v-tab', { hasText: 'Documents' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Actions' })).toHaveCount(0)
  await page.screenshot({ path: `${OUT}/after-bob-colleague.jpg`, type: 'jpeg', quality: 70 })

  await page.goto('/company/documents/people', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page).not.toHaveURL(/\/company\/documents\//)
})

test('rôle de gestion : supervision documentaire intacte', async ({ page }) => {
  await loginCompany(page, 'admin@leezr.test')
  await page.goto('/company/people/user-3', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('.v-tab', { hasText: 'Documents' })).toHaveCount(1)
})
