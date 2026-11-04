// @ts-check
import { mkdirSync } from 'fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers'

const OUT = process.env.E3R3_DIR || '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/e3r-r3-after'
mkdirSync(OUT, { recursive: true })

async function waitList(page) {
  await page.waitForResponse(r => r.url().includes('/workforce/employees') && r.request().method() === 'GET', { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(1600)
}
async function waitFiche(page) {
  await page.getByText('Djamel', { exact: false }).first().waitFor({ state: 'visible', timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(1600)
}

test('captures annuaire + fiche', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page, 'owner@leezr.test')

  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await waitList(page)
  await page.screenshot({ path: `${OUT}/annuaire-390.png` })

  await page.setViewportSize({ width: 1366, height: 1000 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await waitList(page)
  await page.screenshot({ path: `${OUT}/annuaire-1366.png` })

  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await waitFiche(page)
  await page.screenshot({ path: `${OUT}/fiche-390.png` })

  await page.setViewportSize({ width: 1600, height: 1000 })
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await waitFiche(page)
  await page.screenshot({ path: `${OUT}/fiche-1600.png` })
})
