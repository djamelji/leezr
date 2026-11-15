// ADR-792 (L2) — recette « Informations & documents demandés » (société 1, LECTURE + APERÇU uniquement :
// aucun enregistrement — l'enregistrement est couvert par les tests PHPUnit isolés).
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/adr792'
const settle = page => page.waitForTimeout(2000)

test('propriétaire : configurer le poste Livreur, aperçu serveur, aucune écriture', async ({ page }) => {
  const writes = []
  page.on('request', r => { if (r.method() === 'POST' && r.url().endsWith('/api/workforce/requirements')) writes.push(r.url()) })
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/settings', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.getByText('Informations & documents demandés').first()).toBeVisible()
  await page.getByText('Informations & documents demandés').first().click()
  await settle(page)
  await expect(page.locator('[data-test="requirements-parallel-notice"]')).toBeVisible()

  await page.locator('[data-test="requirements-scope"]').click()
  await page.getByRole('option', { name: /Livreur/ }).click()
  await settle(page)
  await page.locator('[data-test="req-add-document"] input').fill('Permis')
  await page.getByRole('option', { name: /Permis/ }).first().click()
  await page.getByRole('button', { name: 'Ajouter' }).last().click()
  await expect(page.locator('[data-test="requirements-pending-bar"]')).toBeVisible()
  await page.screenshot({ path: `${OUT}/after-requirements-desktop-light.jpg`, fullPage: true, type: 'jpeg', quality: 70 })

  await page.locator('[data-test="requirements-preview"]').click()
  await expect(page.getByText('Aperçu des modifications')).toBeVisible()
  await expect(page.getByText('Ajoutées')).toBeVisible()
  await page.waitForTimeout(600)
  await page.screenshot({ path: `${OUT}/after-requirements-preview.jpg`, type: 'jpeg', quality: 70 })
  await page.getByRole('button', { name: 'Annuler' }).last().click()
  expect(writes, 'aucun enregistrement').toEqual([])
})

for (const [name, size, scheme] of [['tablet-light', { width: 820, height: 1180 }, 'light'], ['mobile-light', { width: 390, height: 844 }, 'light'], ['desktop-dark', { width: 1440, height: 900 }, 'dark'], ['mobile-dark', { width: 390, height: 844 }, 'dark']]) {
  test(`affichage ${name}`, async ({ page }) => {
    await page.setViewportSize(size)
    await page.emulateMedia({ colorScheme: scheme })
    await loginCompany(page, 'owner@leezr.test')
    await page.goto('/company/workforce/requirements', { waitUntil: 'domcontentloaded' })
    await settle(page)
    await expect(page.locator('[data-test="requirements-parallel-notice"]')).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false)
    await page.screenshot({ path: `${OUT}/after-requirements-${name}.jpg`, fullPage: true, type: 'jpeg', quality: 70 })
  })
}

test('livreur : ni entrée ni accès', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/requirements', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await expect(page.locator('[data-test="requirements-parallel-notice"]')).toHaveCount(0)
})
