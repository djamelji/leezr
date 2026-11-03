// @ts-check
// E3-A — captures « AVANT » (annuaire + fiche collaborateur) sur l'application réelle.
// Référence pour la comparaison avant/après de la refonte ergonomique.
import { mkdirSync } from 'fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers'

const OUT = process.env.E3_CAPTURE_DIR || '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/e3a-before'
mkdirSync(OUT, { recursive: true })
const EMP = process.env.E3_EMP_ID || '1'

async function shoot(page, name, { dark = false, file = false } = {}) {
  if (dark) {
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.reload({ waitUntil: 'domcontentloaded' })
  }
  const needle = file ? '/file' : '/workforce/employees'
  await page.waitForResponse(r => r.url().includes(needle) && r.request().method() === 'GET', { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(1500)
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false })
  if (dark) await page.emulateMedia({ colorScheme: 'light' })
}

test('AVANT — annuaire', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [1920, 1366]) {
    await page.setViewportSize({ width: w, height: 1000 })
    await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
    await shoot(page, `annuaire-${w}`)
  }
  await page.setViewportSize({ width: 1366, height: 1000 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await shoot(page, 'annuaire-dark', { dark: true })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await shoot(page, 'annuaire-390')
})

test('AVANT — fiche collaborateur', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [1920, 1366]) {
    await page.setViewportSize({ width: w, height: 1000 })
    await page.goto(`/company/workforce/employees/${EMP}`, { waitUntil: 'domcontentloaded' })
    await shoot(page, `fiche-${w}`, { file: true })
  }
  await page.setViewportSize({ width: 1366, height: 1000 })
  await page.goto(`/company/workforce/employees/${EMP}`, { waitUntil: 'domcontentloaded' })
  await shoot(page, 'fiche-dark', { dark: true, file: true })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(`/company/workforce/employees/${EMP}`, { waitUntil: 'domcontentloaded' })
  await shoot(page, 'fiche-390', { file: true })
})
