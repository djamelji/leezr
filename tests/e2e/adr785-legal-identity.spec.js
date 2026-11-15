// ADR-785 — recette du profil Company : champ APE/NAF, arrivée depuis un document bloqué (?missing=),
// erreur de format sous le champ (aucune écriture : la requête est refusée en 422), lien de retour.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/adr785'

test('profil : NAF visible, champs à compléter signalés, format refusé sous le champ', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/profile/overview?missing=naf_code,company_city&back=/company/workforce/dsn')
  const alert = page.locator('[data-test="profile-missing"]')
  await expect(alert).toBeVisible()
  await expect(alert).toContainText('Code APE (NAF)')
  await expect(alert).not.toContainText('Ville') // déjà renseignée : non signalée
  await expect(page.getByText('Retour', { exact: false }).first()).toBeVisible()
  const naf = page.getByLabel('Code APE (NAF)')
  await expect(naf).toBeVisible()
  await page.screenshot({ path: `${OUT}/profile-missing-desktop.jpg`, fullPage: true })

  const initial = await naf.inputValue()
  await naf.fill('XX')
  const resp = page.waitForResponse(r => r.url().endsWith('/api/company') && r.request().method() === 'PUT')
  await page.locator('[data-test="company-save"]').click()
  expect((await resp).status()).toBe(422)
  await expect(page.getByText('4 chiffres et 1 lettre').first()).toBeVisible()
  await expect(page.getByText('dynamic fields')).toHaveCount(0)
  await page.screenshot({ path: `${OUT}/profile-naf-invalid-desktop.jpg`, fullPage: true })
  await naf.fill(initial) // rien n'a été enregistré : on remet la valeur affichée
})

test('profil mobile : alerte et champ NAF lisibles', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/profile/overview?missing=naf_code')
  await expect(page.locator('[data-test="profile-missing"]')).toBeVisible()
  await expect(page.getByLabel('Code APE (NAF)')).toBeVisible()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
  expect(overflow).toBe(false)
  await page.screenshot({ path: `${OUT}/profile-missing-mobile.jpg`, fullPage: true })
})
