// ADR-796 (L6) / ADR-796.1 — recette du dossier d'intégration : RH prépare → invitation envoyée par e-mail
// (Mailpit LOCAL uniquement, aucun destinataire réel) → recrue ouvre le lien reçu (sans compte, sans session
// Company, mobile) → renseigne → dépose (QUARANTAINE : aucun moteur en DEV, pièce inutilisable) → soumet →
// RH constate le blocage → analyse SIMULÉE (adaptateur DEV, pas une analyse) → RH valide.
// Fixture ISOLÉE : nom fictif « E2E Recrue <stamp> », supprimée en fin de recette par son id EXACT
// (onboarding:cleanup-fixtures), jamais par préfixe. Le lien (secret) est masqué sur toutes les captures.
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/adr796'
const MAILPIT = 'http://localhost:8025/api/v1'
const settle = (page, ms = 1200) => page.waitForTimeout(ms)
const artisan = (...args) => execFileSync('php', ['artisan', ...args], { encoding: 'utf8' })
const tinker = code => artisan('tinker', '--execute', code).trim().split('\n').pop()

async function mailTo(request, to) {
  for (let i = 0; i < 20; i++) {
    const res = await (await request.get(`${MAILPIT}/search?query=${encodeURIComponent(`to:"${to}"`)}`)).json()
    if (res.messages?.length) return (await request.get(`${MAILPIT}/message/${res.messages[0].ID}`)).json()
    await new Promise(r => setTimeout(r, 500))
  }
  throw new Error('invitation e-mail not received by the local Mailpit')
}

test('parcours complet : invitation e-mail, dossier mobile, quarantaine, validation RH', async ({ page, browser, request }) => {
  test.setTimeout(240000)
  const stamp = Date.now().toString().slice(-6)
  const email = `e2e-recrue-${stamp}@example.test`
  let employeeId = null

  try {
    await loginCompany(page, 'owner@leezr.test')
    await page.goto('/company/people', { waitUntil: 'domcontentloaded' })
    const panel = page.locator('[data-test="onboarding-panel"]')
    await expect(panel).toBeVisible({ timeout: 15000 })

    // ── RH : préparation (bureau) ──
    await page.locator('[data-test="onboarding-invite"]').click()
    await page.locator('[data-test="onb-first-name"] input').fill('E2E Recrue')
    await page.locator('[data-test="onb-last-name"] input').fill(stamp)
    await page.locator('[data-test="onb-email"] input').fill(email)
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
    await page.locator('[data-test="onb-create-mail"]').click()
    await expect(page.locator('[data-test="onboarding-mailed"]')).toBeVisible({ timeout: 60000 }) // worker PHP DEV parfois occupé par le flux temps réel
    await page.screenshot({ path: `${OUT}/hr-invitation-mailed.jpg`, type: 'jpeg', quality: 70 })
    await page.keyboard.press('Escape')
    employeeId = Number(tinker(`echo App\\Core\\Workforce\\Employee::withoutGlobalScopes()->where('first_name','E2E Recrue')->where('last_name','${stamp}')->value('id');`))
    expect(employeeId).toBeGreaterThan(0)

    // ── E-mail reçu par le Mailpit local : le lien n'existe que là ──
    const mail = await mailTo(request, email)
    const link = (mail.Text.match(/https?:\/\/\S+\/onboarding#t=[a-f0-9]{64}/) || [])[0]
    expect(link, 'lien personnel dans l’e-mail').toBeTruthy()

    // ── Recrue : nouveau navigateur, aucune session Company, mobile ──
    const candidateCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true })
    const cand = await candidateCtx.newPage()
    const url = new URL(link)
    await cand.goto(`${new URL(page.url()).origin}${url.pathname}${url.hash}`)
    await expect(cand.getByText('Votre dossier d’arrivée').or(cand.getByText("Votre dossier d'arrivée"))).toBeVisible({ timeout: 15000 })
    expect(cand.url()).not.toContain('#t=') // le jeton a quitté la barre d'adresse
    await expect(cand.locator('nav, .layout-navbar, .layout-vertical-nav')).toHaveCount(0)
    await settle(cand)
    await cand.screenshot({ path: `${OUT}/candidate-start-mobile.jpg`, fullPage: true, type: 'jpeg', quality: 70 })

    await cand.getByLabel(/Nationalit/).fill('Française')
    await cand.locator('[data-test="onboarding-save"]').click()
    await expect(cand.getByText('Enregistré')).toBeVisible()
    await cand.locator('[data-test="onboarding-doc-driving_license"] input[type="file"]').setInputFiles({ name: 'permis-fictif.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF') })
    await expect(cand.getByText(/analyse de sécurité en cours/)).toBeVisible({ timeout: 15000 }) // quarantaine, jamais « reçu et utilisable »
    await settle(cand)
    await cand.screenshot({ path: `${OUT}/candidate-quarantine-mobile.jpg`, fullPage: true, type: 'jpeg', quality: 70 })

    await cand.reload() // reprise par le cookie de session
    await expect(cand.getByLabel(/Nationalit/)).toHaveValue('Française', { timeout: 15000 })
    await cand.locator('[data-test="onboarding-submit"]').click()
    await expect(cand.locator('[data-test="onboarding-submitted"]')).toBeVisible()
    await cand.emulateMedia({ colorScheme: 'dark' })
    await settle(cand)
    await cand.screenshot({ path: `${OUT}/candidate-submitted-mobile-dark.jpg`, fullPage: true, type: 'jpeg', quality: 70 })

    const other = await candidateCtx.newPage() // lien invalide : état explicite, sans fuite
    await other.goto(`${new URL(page.url()).origin}/onboarding#t=${'0'.repeat(64)}`)
    await expect(other.locator('[data-test="onboarding-invalid"]')).toBeVisible({ timeout: 15000 })
    await other.screenshot({ path: `${OUT}/candidate-invalid-link.jpg`, type: 'jpeg', quality: 70 })
    await candidateCtx.close()

    // ── RH : pièce non analysée → validation bloquée ──
    await page.reload()
    const row = panel.locator('.onb-row', { hasText: stamp })
    await expect(row).toBeVisible({ timeout: 15000 })
    await row.getByRole('button').click()
    await expect(page.locator('[data-test="onb-blocking"]')).toBeVisible({ timeout: 15000 })
    await expect(page.locator('[data-test="onb-validate"]')).toBeDisabled()
    await settle(page, 600)
    await page.screenshot({ path: `${OUT}/hr-review-blocked.jpg`, type: 'jpeg', quality: 70 })

    // Révision prévisualisée (non confirmée) : conséquences visibles pour un dossier soumis.
    await page.locator('[data-test="onb-revise"]').click()
    await page.locator('[data-test^="onb-revise-field-"]:not(:has(input:checked))').first().locator('input[type="checkbox"]').click()
    await page.locator('[data-test="onb-revision-preview-btn"]').click()
    await expect(page.locator('[data-test="onb-revision-preview"]')).toBeVisible()
    await expect(page.locator('[data-test="onb-revision-confirm"]')).toBeDisabled() // message obligatoire après soumission
    await settle(page, 400)
    await page.screenshot({ path: `${OUT}/hr-revision-preview.jpg`, type: 'jpeg', quality: 70 })
    await page.locator('[data-test="onb-revision-cancel"]').click()
    await page.keyboard.press('Escape')

    // Analyse SIMULÉE du seul dépôt de cette fixture (adaptateur DEV, refusé en production).
    const uploadId = tinker(`echo App\\Modules\\Workforce\\Onboarding\\Models\\OnboardingUpload::whereIn('onboarding_invitation_id', App\\Modules\\Workforce\\Onboarding\\Models\\OnboardingInvitation::where('employee_id', ${employeeId})->pluck('id'))->value('id');`)
    expect(artisan('onboarding:scan-uploads', '--simulate', `--upload=${uploadId}`)).toContain('"ingested":1')

    await page.reload()
    await panel.locator('.onb-row', { hasText: stamp }).getByRole('button').click()
    await expect(page.locator('[data-test="onb-review-nationality"]')).toBeVisible()
    await expect(page.locator('[data-test="onb-validate"]')).toBeEnabled()
    await settle(page, 600)
    await page.screenshot({ path: `${OUT}/hr-review.jpg`, type: 'jpeg', quality: 70 })
    await page.locator('[data-test="onb-validate"]').click()
    await expect(page.getByText('Validé').first()).toBeVisible({ timeout: 15000 })
    await page.screenshot({ path: `${OUT}/hr-validated.jpg`, type: 'jpeg', quality: 70 })
  }
  finally {
    // Fixture isolée : supprimée par son id exact (garde-fous de la commande : nom fictif, inactive, sans compte, sans trace opérationnelle).
    if (employeeId) artisan('onboarding:cleanup-fixtures', String(employeeId), '--apply')
  }
})
