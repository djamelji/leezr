// @ts-check
// E3-A — captures « APRÈS » (annuaire + fiche refondus) sur l'application réelle, pour comparaison.
import { mkdirSync } from 'fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers'

const OUT = process.env.E3_CAPTURE_DIR || '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/e3a-after'
mkdirSync(OUT, { recursive: true })
const EMP = process.env.E3_EMP_ID || '1'

async function waitList(page) {
  await page.waitForResponse(r => r.url().includes('/workforce/employees') && r.request().method() === 'GET', { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(1500)
}
async function waitFile(page) {
  await page.waitForResponse(r => r.url().includes('/file') && r.request().method() === 'GET', { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(1500)
}
async function dark(page) {
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.reload({ waitUntil: 'domcontentloaded' })
}

test('APRÈS — annuaire', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [1920, 1366, 768]) {
    await page.setViewportSize({ width: w, height: 1000 })
    await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
    await waitList(page)
    await page.screenshot({ path: `${OUT}/annuaire-${w}.png`, fullPage: false })
  }
  await page.setViewportSize({ width: 1366, height: 1000 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await dark(page); await waitList(page)
  await page.screenshot({ path: `${OUT}/annuaire-dark.png`, fullPage: false })
  await page.emulateMedia({ colorScheme: 'light' })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await waitList(page)
  await page.screenshot({ path: `${OUT}/annuaire-390.png`, fullPage: false })
})

test('APRÈS — fiche collaborateur', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [1920, 1366, 768]) {
    await page.setViewportSize({ width: w, height: 1000 })
    await page.goto(`/company/workforce/employees/${EMP}`, { waitUntil: 'domcontentloaded' })
    await waitFile(page)
    await page.screenshot({ path: `${OUT}/fiche-${w}.png`, fullPage: false })
  }
  await page.setViewportSize({ width: 1366, height: 1000 })
  await page.goto(`/company/workforce/employees/${EMP}`, { waitUntil: 'domcontentloaded' })
  await dark(page); await waitFile(page)
  await page.screenshot({ path: `${OUT}/fiche-dark.png`, fullPage: false })
  await page.emulateMedia({ colorScheme: 'light' })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(`/company/workforce/employees/${EMP}`, { waitUntil: 'domcontentloaded' })
  await waitFile(page)
  await page.screenshot({ path: `${OUT}/fiche-390.png`, fullPage: false })
})
