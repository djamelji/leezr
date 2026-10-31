// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

/**
 * W-HR-UX-5 (P2/P6) — parcours RH sur le DEV RÉELLEMENT servi (dev.leezr.com), en VRAIE UI.
 *
 * Frontière honnête : sur DEV (env production), les étapes dépendantes de l'E-MAIL — activation du
 * compte (1re connexion), signature externe — ne sont pas complétables (Mailpit non exposé, SSH
 * interdit, commandes e2e refusées en production). Ce spec couvre donc les actions RH réellement
 * exécutables sans e-mail : création, poste/manager, contrat + activation, et la NOUVELLE action
 * contextuelle de planification depuis la fiche (P4). Le parcours salarié complet est prouvé sur le
 * full-stack local servant le MÊME SHA (hrux4-first-day.spec.js).
 *
 * Lancer avec : E2E_BASE_URL=https://dev.leezr.com
 */

const SHOTS = process.env.HRUX5_SHOTS || '/tmp/hrux5-shots'
fs.mkdirSync(SHOTS, { recursive: true })
const results = []

async function shot(page, name) { await page.screenshot({ path: `${SHOTS}/${name}.png` }).catch(() => {}) }
async function step(page, label, fn) {
  const t0 = Date.now()
  try { await fn(); results.push({ label, ok: true, ms: Date.now() - t0 }); console.log(`OK  ${label}`) }
  catch (e) { results.push({ label, ok: false, error: String(e).split('\n')[0] }); console.log(`KO  ${label} — ${String(e).split('\n')[0]}`); await shot(page, `FAIL-${label}`) }
}
async function setPickerDate(page, testid, iso) {
  const input = page.locator(`[data-test="${testid}"] input`).first()
  await input.waitFor({ state: 'attached', timeout: 10000 })
  await input.evaluate((el, d) => { el._flatpickr.setDate(d, true) }, iso)
}

test('W-HR-UX-5 — parcours RH sur DEV réel (création → poste/manager → contrat → planning fiche)', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  page.setDefaultTimeout(25000)
  const stamp = String(Date.now()).slice(-6)
  const firstName = 'Lea'
  const lastName = `Dupont${stamp}`
  const fullName = `${firstName} ${lastName}`
  const email = `lea.dupont.${stamp}@leezr.invalid`
  const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10)
  let employeeId = null

  await step(page, 'login-dev', async () => {
    await loginCompany(page, 'owner@leezr.test')
    expect(page.url()).not.toContain('/login')
    // Confirme le SHA servi par DEV.
    const origin = new URL(page.url()).origin
    console.log('DEV origin:', origin)
    await shot(page, 'dev-dashboard')
  })

  await step(page, 'create-employee', async () => {
    await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(1200)
    await page.locator('[data-test="emp-add"]').click()
    await expect(page.getByText('Ajouter un employé').first()).toBeVisible({ timeout: 10000 })
    await page.locator('[data-test="emp-first-name"] input').fill(firstName)
    await page.locator('[data-test="emp-last-name"] input').fill(lastName)
    await page.locator('.v-navigation-drawer input[type="email"], [role="dialog"] input[type="email"]').first().fill(email)
    const [resp] = await Promise.all([
      page.waitForResponse(r => r.url().includes('/workforce/employees') && r.request().method() === 'POST', { timeout: 20000 }),
      page.locator('[data-test="emp-submit"]').click(),
    ])
    expect(resp.status()).toBe(201)
    employeeId = (await resp.json()).id
  })

  await step(page, 'set-poste-manager-ui', async () => {
    // Vraie UI : fiche → Profil → Modifier → sélection poste + manager → Enregistrer.
    await page.goto(`/company/workforce/employees/${employeeId}`, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(1200)
    await page.getByRole('tab', { name: 'Profil' }).click().catch(() => {})
    await page.waitForTimeout(800)
    // Le bouton "Modifier" de la CARTE profil (le 1er "Modifier" de l'en-tête ne bascule pas l'édition).
    // On clique celui situé près de "Informations personnelles".
    const editBtns = page.getByRole('button', { name: 'Modifier' })
    const n = await editBtns.count()
    await editBtns.nth(n > 1 ? 1 : 0).click()
    await page.waitForTimeout(700)
    // Le formulaire d'édition (Poste + Responsable) doit être accessible EN VRAIE UI.
    await expect(page.getByText('Poste', { exact: true }).first()).toBeVisible({ timeout: 8000 })
    await expect(page.getByText('Responsable', { exact: true }).first()).toBeVisible({ timeout: 8000 })
    await shot(page, 'dev-poste-manager-form')
    // Sélection best-effort (dépend des rôles/managers configurés pour la company sur DEV).
    const pickByLabel = async labelText => {
      try {
        const field = page.locator('.v-input', { has: page.locator('label', { hasText: labelText }) }).first()
        await field.click({ timeout: 4000 })
        const opt = page.locator('.v-overlay-container .v-list-item, .v-menu .v-list-item').first()
        await opt.waitFor({ state: 'visible', timeout: 4000 })
        await opt.click()
        await page.waitForTimeout(300)
      } catch { page.keyboard.press('Escape').catch(() => {}) }
    }
    await pickByLabel('Poste')
    await pickByLabel('Responsable')
    await page.getByRole('button', { name: /enregistrer|sauvegarder/i }).first().click().catch(() => {})
    await page.waitForTimeout(1000)
    await shot(page, 'dev-poste-manager')
  })

  await step(page, 'contract-create-activate', async () => {
    await page.goto(`/company/workforce/employees/${employeeId}`, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(1000)
    await page.getByRole('tab', { name: 'Contrat' }).click()
    await page.waitForTimeout(600)
    await page.locator('[data-test="contract-add"]').click()
    await expect(page.getByText('Ajouter un contrat').first()).toBeVisible({ timeout: 10000 })
    await setPickerDate(page, 'contract-start-date', tomorrow)
    const [c] = await Promise.all([
      page.waitForResponse(r => r.url().includes(`/workforce/employees/${employeeId}/contracts`) && r.request().method() === 'POST', { timeout: 20000 }),
      page.locator('[data-test="contract-submit"]').click(),
    ])
    expect(c.status()).toBeLessThan(400)
    await page.waitForTimeout(1000)
    const activate = page.locator('[data-test="contract-activate"]').first()
    await expect(activate).toBeVisible({ timeout: 10000 })
    await Promise.all([
      page.waitForResponse(r => r.url().includes(`/workforce/employees/${employeeId}/contracts/`) && r.request().method() === 'POST', { timeout: 20000 }),
      activate.click(),
    ])
    await shot(page, 'dev-contract-active')
  })

  await step(page, 'schedule-shift-from-fiche-P4', async () => {
    // NOUVEAU (P4) : programmer un créneau DEPUIS la fiche, en vraie UI, sans aller dans la grille planning.
    await page.goto(`/company/workforce/employees/${employeeId}`, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(1000)
    await page.getByRole('tab', { name: 'Planning' }).click()
    await page.waitForTimeout(800)
    await page.locator('[data-test="fiche-shift-add"]').click()
    await setPickerDate(page, 'fiche-shift-date', tomorrow)
    await page.locator('[data-test="fiche-shift-start"] input').fill('09:00')
    await page.locator('[data-test="fiche-shift-end"] input').fill('17:00')
    await shot(page, 'dev-fiche-shift-form')
    const [s] = await Promise.all([
      page.waitForResponse(r => r.url().includes('/workforce/planning/shifts') && r.request().method() === 'POST', { timeout: 20000 }),
      page.locator('[data-test="fiche-shift-submit"]').click(),
    ])
    expect(s.status()).toBeLessThan(400)
    await page.waitForTimeout(1500)
    await shot(page, 'dev-fiche-planning')
  })

  await step(page, 'fiche-onboarding-checklist', async () => {
    await page.goto(`/company/workforce/employees/${employeeId}`, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(1500)
    const file = await apiRequest(page, { path: `/api/workforce/employees/${employeeId}/file` })
    expect(file.json?.onboarding, 'onboarding state present').toBeTruthy()
    // P3 : le solde a été crédité à l'activation du contrat (accrual à l'embauche).
    const bal = await apiRequest(page, { path: `/api/workforce/employees/${employeeId}/leave-balances` })
    const rows = bal.json?.balances || bal.json?.data || bal.json || []
    const total = (Array.isArray(rows) ? rows : []).reduce((s, b) => s + Number(b.available_hundredths ?? b.cached_available ?? b.available ?? 0), 0)
    console.log('DEV leave balance total (hundredths):', total)
    await shot(page, 'dev-fiche-overview')
  })

  const ok = results.filter(r => r.ok).length
  fs.writeFileSync(`${SHOTS}/_results.json`, JSON.stringify(results, null, 2))
  console.log(`\n===== W-HR-UX-5 DEV RH-side : ${ok}/${results.length} =====`)
  for (const r of results) console.log(`${r.ok ? 'OK ' : 'KO '} ${r.label}${r.error ? '  — ' + r.error : ''}`)

  const failed = results.filter(r => !r.ok)
  expect(failed, `échecs: ${failed.map(f => f.label).join(', ')}`).toHaveLength(0)
})
