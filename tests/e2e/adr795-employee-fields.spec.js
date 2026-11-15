// ADR-795 (L5) — recette « Informations complémentaires » d'un salarié SANS compte (société 1, LECTURE
// uniquement : aucune valeur enregistrée sur DEV — l'écriture est couverte par PHPUnit isolé).
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/adr795'

test('propriétaire : fiche d’un salarié sans compte, informations complémentaires éditables, aucune écriture', async ({ page }) => {
  const writes = []
  page.on('request', r => { if (r.method() === 'PUT' && r.url().includes('/fields')) writes.push(r.url()) })
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/people/employee-4?tab=profile', { waitUntil: 'domcontentloaded' })
  const block = page.locator('[data-test="employee-fields"]')
  await expect(block).toBeVisible({ timeout: 15000 })
  await expect(block.getByText('Informations complémentaires')).toBeVisible()
  await expect(block.getByText('même sans compte Leezr')).toBeVisible()
  await expect(block.locator('[data-test="employee-fields-save"]')).toBeDisabled()
  await block.scrollIntoViewIfNeeded()
  await page.waitForTimeout(800)
  await page.screenshot({ path: `${OUT}/employee-fields-accountless.jpg`, fullPage: true, type: 'jpeg', quality: 70 })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(1200)
  await block.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `${OUT}/employee-fields-mobile.jpg`, type: 'jpeg', quality: 70 })
  expect(writes, 'aucune écriture').toEqual([])
})
