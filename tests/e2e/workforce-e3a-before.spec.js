// @ts-check
// E3-A — captures « AVANT » (annuaire + fiche) sur l'ANCIENNE UI (build stashé). Attend un contenu
// réel à l'écran (pas seulement le réseau) pour éviter de capturer le splash de boot du SPA.
import { mkdirSync } from 'fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers'

const OUT = process.env.E3_CAPTURE_DIR || '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/e3a-before'
mkdirSync(OUT, { recursive: true })
const EMP = process.env.E3_EMP_ID || '1'

async function shootAnnuaire(page, name, dark = false) {
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  if (dark) {
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.reload({ waitUntil: 'domcontentloaded' })
  }
  // Attendre une ligne de données réelle (fin du splash + fin du chargement table).
  await page.getByText('Thomas Bernard', { exact: false }).first().waitFor({ state: 'visible', timeout: 20000 }).catch(() => {})
  await page.waitForTimeout(800)
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false })
  if (dark) await page.emulateMedia({ colorScheme: 'light' })
}

async function shootFiche(page, name, dark = false) {
  await page.goto(`/company/workforce/employees/${EMP}`, { waitUntil: 'domcontentloaded' })
  if (dark) {
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.reload({ waitUntil: 'domcontentloaded' })
  }
  // Attendre l'onglet « Vue d'ensemble » / le nom (fin du splash + fin du spinner fiche).
  await page.getByText('Djamel', { exact: false }).first().waitFor({ state: 'visible', timeout: 20000 }).catch(() => {})
  await page.waitForTimeout(1000)
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false })
  if (dark) await page.emulateMedia({ colorScheme: 'light' })
}

test('AVANT — annuaire', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [1920, 1366, 768]) {
    await page.setViewportSize({ width: w, height: 1000 })
    await shootAnnuaire(page, `annuaire-${w}`)
  }
  await page.setViewportSize({ width: 1366, height: 1000 })
  await shootAnnuaire(page, 'annuaire-dark', true)
  await page.setViewportSize({ width: 390, height: 844 })
  await shootAnnuaire(page, 'annuaire-390')
})

test('AVANT — fiche collaborateur', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [1920, 1366, 768]) {
    await page.setViewportSize({ width: w, height: 1000 })
    await shootFiche(page, `fiche-${w}`)
  }
  await page.setViewportSize({ width: 1366, height: 1000 })
  await shootFiche(page, 'fiche-dark', true)
  await page.setViewportSize({ width: 390, height: 844 })
  await shootFiche(page, 'fiche-390')
})
