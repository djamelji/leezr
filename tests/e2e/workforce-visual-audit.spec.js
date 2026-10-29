// @ts-check
// W-CONTRACT-3B — audit visuel réel (captures à inspecter), pas une simple assertion DOM.
import { test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

const COMPANY = [
  ['employees', '/company/workforce/employees'],
  ['contract-library', '/company/workforce/contract/templates'],
  ['planning', '/company/workforce/planning'],
  ['time', '/company/workforce/time'],
  ['leave', '/company/workforce/leave'],
  ['settings', '/company/workforce/settings/general'],
]

test('capture Workforce company screens (desktop + dark)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const [name, url] of COMPANY) {
    await page.goto(url, { waitUntil: 'networkidle' })
    await page.waitForTimeout(900)
    await page.screenshot({ path: `${SCRATCH}/audit-${name}.png`, fullPage: false })
  }
  // Fiche collaborateur.
  await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
  await page.locator('.v-data-table tbody tr').first().click()
  await page.waitForTimeout(800)
  await page.screenshot({ path: `${SCRATCH}/audit-fiche.png`, fullPage: false })

})

test('capture dark mode réel (prefers-color-scheme) sur écrans corrigés', async ({ page }) => {
  test.setTimeout(120000)
  // Thème par défaut = 'system' → suit prefers-color-scheme (émulé dark).
  await page.emulateMedia({ colorScheme: 'dark' })
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/contract/templates', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1200)
  await page.screenshot({ path: `${SCRATCH}/audit-contract-dark.png`, fullPage: false })
  // Congés dark (KPI corrigées).
  await page.goto('/company/workforce/leave', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1200)
  await page.screenshot({ path: `${SCRATCH}/audit-leave-dark.png`, fullPage: false })
  // Fiche dark (sous-cartes outlined).
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)
  await page.locator('.v-data-table tbody tr').first().click()
  await page.waitForTimeout(1000)
  await page.screenshot({ path: `${SCRATCH}/audit-fiche-dark.png`, fullPage: false })
})

test('capture responsive (mobile 390 / tablette 768) sur écrans corrigés', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const [w, tag] of [[390, 'mobile'], [768, 'tablet']]) {
    await page.setViewportSize({ width: w, height: 900 })
    await page.goto('/company/workforce/leave', { waitUntil: 'networkidle' })
    await page.waitForTimeout(800)
    await page.screenshot({ path: `${SCRATCH}/audit-leave-${tag}.png`, fullPage: false })
    await page.goto('/company/workforce/employees', { waitUntil: 'networkidle' })
    await page.locator('.v-data-table tbody tr').first().click()
    await page.waitForTimeout(700)
    await page.screenshot({ path: `${SCRATCH}/audit-fiche-${tag}.png`, fullPage: false })
  }
})

test('capture Platform contract catalogue', async ({ page }) => {
  await loginPlatform(page)
  await page.goto('/platform/contract-templates', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)
  await page.screenshot({ path: `${SCRATCH}/audit-platform-catalog.png`, fullPage: false })
})
