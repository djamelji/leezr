// ADR-796 (L6) — recette du dossier d'intégration : RH prépare → recrue renseigne et dépose (sans compte,
// sans session Company) → soumet → RH valide. Données FICTIVES (« E2E Recrue … ») conservées comme registre
// d'exécution sur DEV ; le lien (secret) est masqué sur toutes les captures ; aucun e-mail envoyé.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/adr796'
const settle = (page, ms = 1200) => page.waitForTimeout(ms)

test('parcours complet : préparation, dossier candidat mobile, soumission, validation RH', async ({ page, browser }) => {
  const stamp = Date.now().toString().slice(-6)
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/people', { waitUntil: 'domcontentloaded' })
  const panel = page.locator('[data-test="onboarding-panel"]')
  await expect(panel).toBeVisible({ timeout: 15000 })

  // ── RH : préparation ──
  await page.locator('[data-test="onboarding-invite"]').click()
  await page.locator('[data-test="onb-first-name"] input').fill('E2E Recrue')
  await page.locator('[data-test="onb-last-name"] input').fill(stamp)
  // flatpickr (saisie directe désactivée) : réglé via sa propre instance, comme les autres recettes.
  await page.locator('[data-test="onb-hire-date"] input.flatpickr-input, [data-test="onb-hire-date"] input').first()
    .evaluate(el => { const fp = el._flatpickr || el.closest('[data-test]').querySelector('input')._flatpickr; fp.setDate('2027-01-04', true) })
  await page.locator('[data-test="onb-next"]').click()
  await expect(page.locator('[data-test^="onb-item-"]').first()).toBeVisible()
  const selectedRows = page.locator('[data-test^="onb-item-"][data-selected="true"]')
  while (await selectedRows.count() > 0) // la liste change à chaque décochage
    await selectedRows.first().locator('input[type="checkbox"]').first().click()
  await page.locator('[data-test="onb-item-field-nationality"] input[type="checkbox"]').first().click()
  await page.locator('[data-test="onb-item-document-driving_license"] input[type="checkbox"]').first().click()
  await settle(page, 400)
  await page.screenshot({ path: `${OUT}/hr-prepare-items.jpg`, type: 'jpeg', quality: 70 })
  await page.locator('[data-test="onb-next"]').click()
  await page.locator('[data-test="onb-legal-basis"]').click()
  await page.getByRole('option', { name: /préparation du contrat/ }).click()
  await page.locator('[data-test="onb-create"]').click()
  const linkInput = page.locator('[data-test="onboarding-link"] input')
  await expect(linkInput).toBeVisible()
  const link = await linkInput.inputValue()
  expect(link).toContain('/onboarding#t=')
  await page.screenshot({ path: `${OUT}/hr-link-ready.jpg`, type: 'jpeg', quality: 70, mask: [page.locator('[data-test="onboarding-link"]')] })
  await page.keyboard.press('Escape')

  // ── Recrue : nouveau navigateur, aucune session Company, mobile ──
  const candidateCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true })
  const cand = await candidateCtx.newPage()
  const url = new URL(link)
  await cand.goto(`${new URL(page.url()).origin}${url.pathname}${url.hash}`)
  await expect(cand.getByText('Votre dossier d’arrivée').or(cand.getByText("Votre dossier d'arrivée"))).toBeVisible({ timeout: 15000 })
  expect(cand.url()).not.toContain('#t=') // le jeton a quitté la barre d'adresse
  await expect(cand.locator('nav, .layout-navbar, .layout-vertical-nav')).toHaveCount(0) // aucune navigation Company
  await settle(cand)
  await cand.screenshot({ path: `${OUT}/candidate-start-mobile.jpg`, fullPage: true, type: 'jpeg', quality: 70 })

  await cand.getByLabel(/Nationalit/).fill('Française')
  await cand.locator('[data-test="onboarding-save"]').click()
  await expect(cand.getByText('Enregistré')).toBeVisible()
  await cand.locator('[data-test="onboarding-doc-driving_license"] input[type="file"]').setInputFiles({ name: 'permis-fictif.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF') })
  await expect(cand.getByText(/en attente de vérification/)).toBeVisible({ timeout: 15000 })
  await settle(cand)
  await cand.screenshot({ path: `${OUT}/candidate-filled-mobile.jpg`, fullPage: true, type: 'jpeg', quality: 70 })

  // Reprise : rechargement sans le jeton (cookie de session) → dossier retrouvé
  await cand.reload()
  await expect(cand.getByLabel(/Nationalit/)).toHaveValue('Française', { timeout: 15000 })

  await cand.locator('[data-test="onboarding-submit"]').click()
  await expect(cand.locator('[data-test="onboarding-submitted"]')).toBeVisible()
  await cand.emulateMedia({ colorScheme: 'dark' })
  await settle(cand)
  await cand.screenshot({ path: `${OUT}/candidate-submitted-mobile-dark.jpg`, fullPage: true, type: 'jpeg', quality: 70 })

  // Lien invalide : état explicite, sans fuite
  const other = await candidateCtx.newPage()
  await other.goto(`${new URL(page.url()).origin}/onboarding#t=${'0'.repeat(64)}`)
  await expect(other.locator('[data-test="onboarding-invalid"]')).toBeVisible({ timeout: 15000 })
  await other.screenshot({ path: `${OUT}/candidate-invalid-link.jpg`, type: 'jpeg', quality: 70 })
  await candidateCtx.close()

  // ── RH : vérification et validation ──
  await page.reload()
  const row = panel.locator('.onb-row', { hasText: stamp })
  await expect(row).toBeVisible({ timeout: 15000 })
  await row.getByRole('button').click()
  await expect(page.locator('[data-test="onb-review-nationality"]')).toBeVisible()
  await settle(page, 600)
  await page.screenshot({ path: `${OUT}/hr-review.jpg`, type: 'jpeg', quality: 70 })
  await page.locator('[data-test="onb-validate"]').click()
  await expect(page.getByText('Validé').first()).toBeVisible({ timeout: 15000 })
  await page.screenshot({ path: `${OUT}/hr-validated.jpg`, type: 'jpeg', quality: 70 })
})
