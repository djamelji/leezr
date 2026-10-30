// @ts-check
// W-HR-UX-1 — Preuve UI "après" : la fiche collaborateur affiche la CHECKLIST d'intégration guidée.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers'

const S = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('after: employee fiche shows onboarding checklist', async ({ page }) => {
  test.setTimeout(90_000)
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)

  // Ouvrir la fiche de "Bob Dupont" (collaborateur existant avec contrat mais intégration incomplète).
  await page.getByText('Bob Dupont').first().click()
  await page.waitForTimeout(2500)
  await expect(page).toHaveURL(/\/company\/workforce\/employees\/\d+/)
  await page.screenshot({ path: `${S}/hrux-after-fiche.png`, fullPage: true })

  // La checklist d'intégration est présente et guide la RH.
  await expect(page.getByText(/Intégration du collaborateur/i)).toBeVisible({ timeout: 10_000 })
  await expect(page.getByText(/Contrat de travail/i).first()).toBeVisible()
  const steps = await page.locator('text=/Informations du collaborateur|Contrat de travail|Signature du contrat|Accès à l.espace collaborateur/i').count()
  console.log('CHECKLIST_STEPS_VISIBLE=' + steps)
})
