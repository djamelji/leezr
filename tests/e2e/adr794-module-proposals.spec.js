// ADR-794 (L4) — recette « Proposé par le module … » dans « Informations & documents demandés »
// (société 1, LECTURE + APERÇU uniquement : aucune décision enregistrée — couvert par PHPUnit isolé).
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/adr794'
const settle = page => page.waitForTimeout(2000)

test('propriétaire : découvrir les propositions des modules, aperçu serveur, aucune écriture', async ({ page }) => {
  const writes = []
  page.on('request', r => { if (r.method() === 'POST' && r.url().includes('/decisions')) writes.push(r.url()) })
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/settings', { waitUntil: 'domcontentloaded' })
  await settle(page)
  await page.getByText('Informations & documents demandés').first().click()
  await settle(page)

  const workforce = page.locator('[data-test="module-proposals-workforce"]')
  await expect(workforce).toBeVisible()
  await expect(workforce.getByText('Le module Workforce apporte')).toBeVisible()
  await expect(page.locator('[data-test="module-proposals-workforce_payroll"]').getByText('donnée sensible').first()).toBeVisible()
  await expect(page.locator('body')).not.toContainText('@all')
  await workforce.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `${OUT}/module-proposals-desktop-light.jpg`, fullPage: true, type: 'jpeg', quality: 70 })

  await page.locator('[data-test="module-adopt-workforce-document-id_card"]').click()
  await page.locator('[data-test="module-preview-workforce"]').click()
  await expect(page.getByText('Propositions du module Workforce')).toBeVisible()
  await expect(page.getByText('Adoptées')).toBeVisible()
  await page.waitForTimeout(800)
  await page.screenshot({ path: `${OUT}/module-proposals-preview.jpg`, type: 'jpeg', quality: 70 })
  await page.getByRole('button', { name: 'Annuler' }).last().click()

  await page.setViewportSize({ width: 390, height: 844 })
  await settle(page)
  await workforce.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `${OUT}/module-proposals-mobile.jpg`, fullPage: true, type: 'jpeg', quality: 70 })
  expect(writes, 'aucune décision enregistrée').toEqual([])
})
