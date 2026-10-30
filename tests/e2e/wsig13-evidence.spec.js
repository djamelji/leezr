// @ts-check
// W-SIGNATURE-13 (§9) — consultation d'une demande terminée + export du dossier de preuves (download réel),
// capture du drawer. Données de recette existantes (demandes déjà terminées).
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('open completed request → export evidence package (download) + drawer capture', async ({ page }) => {
  test.setTimeout(90_000)
  const errs = []
  page.on('console', m => { if (m.type() === 'error') errs.push(m.text()) })
  page.on('pageerror', e => errs.push(String(e)))

  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/signature', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1800)

  // Onglet "Terminées" puis ouverture de la première demande terminée.
  await page.getByRole('tab', { name: /Terminées/i }).click()
  await page.waitForTimeout(1200)
  await page.locator('.v-data-table__tr, tbody tr').first().click()
  await page.waitForTimeout(1500)
  await page.screenshot({ path: `${SCRATCH}/wsig13-drawer.png`, fullPage: true })

  // Export du dossier de preuves → déclenche un download JSON.
  const exportBtn = page.getByRole('button', { name: /Exporter le dossier de preuves/i })
  await expect(exportBtn).toBeVisible({ timeout: 10_000 })
  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 20_000 }),
    exportBtn.click(),
  ])
  expect(download.suggestedFilename()).toMatch(/dossier-preuves-\d+\.json/)

  const blocking = errs.filter(e => !/favicon|ResizeObserver|Failed to load resource/i.test(e))
  expect(blocking, 'erreurs console: ' + blocking.join(' | ')).toHaveLength(0)
})
