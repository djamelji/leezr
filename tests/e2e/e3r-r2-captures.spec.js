// @ts-check
// E3-R / R2 — captures (avant/après) des fondations : app bar mobile + pages corrigées.
// Dossier via E3R2_DIR. Attend un contenu réel (évite les captures de splash/chargement).
import { mkdirSync } from 'fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers'

const OUT = process.env.E3R2_DIR || '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/e3r-r2-after'
mkdirSync(OUT, { recursive: true })

async function shoot(page, url, name, ready) {
  await page.goto(url, { waitUntil: 'domcontentloaded' })
  if (ready) await page.getByText(ready, { exact: false }).first().waitFor({ state: 'visible', timeout: 15000 }).catch(() => {})
  else await page.waitForTimeout(3000)
  await page.waitForTimeout(1200)
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false })
}

test('captures mobile 390', async ({ page }) => {
  test.setTimeout(180000)
  await loginCompany(page, 'owner@leezr.test')
  await page.setViewportSize({ width: 390, height: 844 })
  await shoot(page, '/dashboard', 'appbar-dashboard', 'Bienvenue')
  await shoot(page, '/company/workforce/me', 'portal', 'Mon espace')
  await shoot(page, '/company/workforce/payroll', 'payroll', 'Paie')
  await shoot(page, '/company/workforce/leave', 'leave', 'Congés')
})
