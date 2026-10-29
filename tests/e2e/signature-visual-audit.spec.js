import { test } from '@playwright/test'
import { loginCompany } from './helpers'
const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('signature company screens', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/signature', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1800)
  await page.screenshot({ path: `${SCRATCH}/sig-list.png`, fullPage: false })

  // Assistant
  const newBtn = page.getByRole('button', { name: /Nouvelle demande|New request/ })
  if (await newBtn.count()) {
    await newBtn.first().click()
    await page.waitForTimeout(900)
    await page.screenshot({ path: `${SCRATCH}/sig-wizard.png`, fullPage: false })
    await page.keyboard.press('Escape')
  }

  // Paramètres RH → Signature
  await page.goto('/company/workforce/settings/signature', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1800)
  await page.screenshot({ path: `${SCRATCH}/sig-settings.png`, fullPage: false })
})
