// ADR-787 — recette Employee Experience (lecture seule : aucun enregistrement en DEV).
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const settle = page => page.waitForTimeout(2500)
const OUT = 'docs/bmad/adr787'

test('livreur : centre d\'aide utile, administration inaccessible même par URL', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/help-center', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.getByText('Mon espace salarié').first()).toBeVisible()
  for (const admin of ['Facturation', 'Modules', 'Membres', 'Démarrage'])
    await expect(page.getByText(admin, { exact: true })).toHaveCount(0)
  await expect(page.getByText('Contacter le support')).toHaveCount(0)
  for (const shortcut of ['Ajouter un membre', 'Comprendre ma facture', 'Activer un module', 'Un membre ne reçoit pas'])
    await expect(page.getByText(shortcut, { exact: false })).toHaveCount(0)
  await expect(page.getByText('Suivre mes heures').first()).toBeVisible()
  await page.screenshot({ path: `${OUT}/after-help-center-bob.jpg`, fullPage: true, type: 'jpeg', quality: 70 })
  const res = page.waitForResponse(r => r.url().includes('/api/help-center/article/membres/'))
  await page.goto('/help-center/membres/inviter-nouveau-membre', { waitUntil: 'domcontentloaded' })
  expect((await res).status()).toBe(404)
  await settle(page)
  await expect(page.getByText('Inviter un nouveau membre', { exact: false })).toHaveCount(0)
})

test('propriétaire : contenus d\'administration et support', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/help-center', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.getByText('Facturation', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('Ajouter un membre')).toBeVisible()
  await page.screenshot({ path: `${OUT}/after-help-center-owner.jpg`, fullPage: true, type: 'jpeg', quality: 70 })
})

test('livreur : profil verrouillé par le contrat, coordonnées modifiables, préférences redirigées', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/account-settings/preferences', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page).toHaveURL(/\/account-settings\/account/)
  await expect(page.locator('.v-tab', { hasText: 'Préférences' })).toHaveCount(0)
  await expect(page.locator('[data-test="profile-contract-lock"]')).toBeVisible()
  await expect(page.getByLabel('Prénom')).toHaveAttribute('readonly', /.*/)
  const street = page.getByLabel(/Adresse personnelle|Rue/).first()
  if (await street.count()) await expect(street).not.toHaveAttribute('readonly', /.*/)
  await page.screenshot({ path: `${OUT}/after-profile-bob.jpg`, fullPage: true, type: 'jpeg', quality: 70 })
})

test('livreur : Mon activité affiche ses heures', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('[data-test="me-hours"]')).toBeVisible()
  await expect(page.locator('[data-test="stat-hours-week"]')).toBeVisible()
  await expect(page.locator('[data-test="me-open-payroll"]')).toHaveCount(0)
})

test('manager sans fiche salarié : pas de compteur trompeur', async ({ page }) => {
  await loginCompany(page, 'manager@leezr.test')
  const calls = []
  page.on('request', r => { if (r.url().includes('/api/workforce/me/hours')) calls.push(r.url()) })
  await page.goto('/company/workforce/me', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('[data-test="me-hours"]')).toHaveCount(0)
  expect(calls).toHaveLength(0)
})
