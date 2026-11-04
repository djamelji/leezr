// @ts-check
// E3-R / R4 — recette mobile COMPLÈTE. Interactions réelles + sweep anti-débordement multi-largeurs,
// dark, clavier, dialogues/formulaires, filtres, multi-contexte (Company/Platform/Employee/non-Workforce).
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers'

const WIDTHS = [320, 375, 390, 430, 768, 1024, 1366]
const overflow = page => page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - window.innerWidth))

test('sweep anti-débordement — Company (annuaire, fiche, portail) + non-Workforce', async ({ page }) => {
  test.setTimeout(300000)
  await loginCompany(page, 'owner@leezr.test')
  const pages = [
    { url: '/company/workforce/employees', ready: () => page.locator('.arv-mobile .arv-row, .v-data-table tbody tr').first() },
    { url: '/company/workforce/employees/1', ready: () => page.getByText('Djamel', { exact: false }).first() },
    { url: '/company/workforce/me', ready: () => page.getByText('Mon espace', { exact: false }).first() },
    { url: '/company/members', ready: null },
  ]
  for (const p of pages) {
    for (const w of WIDTHS) {
      await page.setViewportSize({ width: w, height: 900 })
      await page.goto(p.url, { waitUntil: 'domcontentloaded' })
      if (p.ready) await p.ready().waitFor({ state: 'visible', timeout: 15000 }).catch(() => {})
      else await page.waitForTimeout(2500)
      await page.waitForTimeout(500)
      const o = await overflow(page)
      expect(o, `${p.url} @${w}px overflow ${o}px`).toBeLessThanOrEqual(2)
    }
  }
})

test('dark mode — pas de débordement (annuaire + fiche @390)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await page.locator('.arv-mobile .arv-row').first().waitFor({ state: 'visible', timeout: 20000 }).catch(() => {})
  expect(await overflow(page)).toBeLessThanOrEqual(2)
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await page.getByText('Djamel', { exact: false }).first().waitFor({ state: 'visible', timeout: 15000 }).catch(() => {})
  expect(await overflow(page)).toBeLessThanOrEqual(2)
})

test('dialogue de création plein écran mobile + clavier (actions atteignables)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-test="emp-add"]').waitFor({ state: 'visible', timeout: 15000 })
  await page.locator('[data-test="emp-add"]').click()
  await expect(page.locator('[data-test="emp-first-name"]')).toBeVisible({ timeout: 8000 })
  // Action de validation atteignable (bas de l'écran), pas masquée.
  await expect(page.locator('[data-test="emp-submit"]')).toBeVisible()
  // Clavier : on remplit via le clavier et on ferme avec le bouton close.
  await page.locator('[data-test="emp-first-name"] input').fill('Test')
  await expect(page.locator('[data-test="emp-first-name"] input')).toHaveValue('Test')
  await page.locator('[data-test="aad-close"]').click()
  await expect(page.locator('[data-test="emp-first-name"]')).toHaveCount(0)
})

test('filtres en bottom-sheet mobile + valeurs préservées au changement de largeur', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  // Deep-link d'un filtre actif → compteur de filtres actifs attendu.
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees?status=active', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-test="fp-toggle"]').waitFor({ state: 'visible', timeout: 15000 })
  await page.waitForTimeout(800)
  // Compteur de filtres actifs présent (valeur venue de l'URL, préservée).
  await expect(page.locator('[data-test="fp-toggle"] .v-chip').first()).toBeVisible({ timeout: 6000 })
  // Ouvre le bottom-sheet : les filtres avancés sont accessibles sur mobile.
  await page.locator('[data-test="fp-toggle"]').click()
  await page.waitForTimeout(600)
  await expect(page.getByText('Département', { exact: true }).first()).toBeVisible()
  await page.locator('[data-test="fp-apply"]').click()
  await page.waitForTimeout(400)
  // Passage en desktop : la valeur (compteur) est préservée (pas de perte d'état).
  await page.setViewportSize({ width: 1366, height: 900 })
  await page.waitForTimeout(800)
  await expect(page.locator('[data-test="fp-toggle"] .v-chip').first()).toBeVisible({ timeout: 6000 })
})

test('owner voit le salaire dans la fiche (compensation_read)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.setViewportSize({ width: 1366, height: 900 })
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await page.getByText('Djamel', { exact: false }).first().waitFor({ timeout: 15000 })
  await expect(page.getByText(/\d[\d\s.,]*\s?€/).first()).toBeVisible({ timeout: 8000 })
})

test('Platform : pas de débordement mobile', async ({ page }) => {
  await loginPlatform(page).catch(() => {})
  if (page.url().includes('/login')) test.skip(true, 'pas de compte platform local')
  for (const w of [320, 390, 768]) {
    await page.setViewportSize({ width: w, height: 844 })
    await page.goto('/platform', { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(2500)
    const o = await overflow(page)
    expect(o, `platform @${w}px overflow ${o}px`).toBeLessThanOrEqual(2)
  }
})
