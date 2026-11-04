// @ts-check
import { mkdirSync } from 'fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers'

const OUT = process.env.R3UX_DIR || '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/e3r-r3ux-after'
mkdirSync(OUT, { recursive: true })

async function annuaire(page, name, dark = false) {
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  if (dark) { await page.emulateMedia({ colorScheme: 'dark' }); await page.reload({ waitUntil: 'domcontentloaded' }) }
  await page.locator('.arv-mobile .arv-row, .v-data-table tbody tr').first().waitFor({ state: 'visible', timeout: 20000 }).catch(() => {})
  await page.waitForTimeout(900)
  await page.screenshot({ path: `${OUT}/${name}.png` })
  if (dark) await page.emulateMedia({ colorScheme: 'light' })
}
async function fiche(page, name, dark = false) {
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  if (dark) { await page.emulateMedia({ colorScheme: 'dark' }); await page.reload({ waitUntil: 'domcontentloaded' }) }
  await page.getByText('Djamel', { exact: false }).first().waitFor({ state: 'visible', timeout: 20000 }).catch(() => {})
  await page.waitForTimeout(1100)
  await page.screenshot({ path: `${OUT}/${name}.png` })
  if (dark) await page.emulateMedia({ colorScheme: 'light' })
}

test('captures annuaire', async ({ page }) => {
  test.setTimeout(180000)
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [320, 390, 768, 1366]) { await page.setViewportSize({ width: w, height: 1000 }); await annuaire(page, `annuaire-${w}`) }
  await page.setViewportSize({ width: 390, height: 844 }); await annuaire(page, 'annuaire-390-dark', true)
})

test('captures fiche', async ({ page }) => {
  test.setTimeout(180000)
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [320, 390, 768, 1366]) { await page.setViewportSize({ width: w, height: 1000 }); await fiche(page, `fiche-${w}`) }
  await page.setViewportSize({ width: 390, height: 844 }); await fiche(page, 'fiche-390-dark', true)
})
