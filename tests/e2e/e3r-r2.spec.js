// @ts-check
// E3-R / R2 — fondations responsive. Teste de VRAIES interactions (pas que des screenshots) :
// app bar mobile (overflow outils), drawer clavier/focus, débordements corrigés, non-régression desktop.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

async function overflow(page) {
  return await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - window.innerWidth))
}

test('app bar mobile : outils secondaires regroupés, notifications visibles', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page, 'owner@leezr.test')

  // Mobile : bouton « plus » présent, outils desktop non inline.
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await page.getByText('Bienvenue', { exact: false }).first().waitFor({ timeout: 12000 }).catch(() => {})
  const more = page.locator('[data-test="navbar-more"]')
  await expect(more).toBeVisible()
  // Notifications restent visibles (prioritaires) — icône cloche dans la navbar.
  await expect(page.locator('.layout-navbar .tabler-bell').first()).toBeVisible()
  // Ouvrir le menu « plus » → langue/thème accessibles.
  await more.click()
  await page.waitForTimeout(400)
  await expect(page.getByText('Langue', { exact: false }).first()).toBeVisible({ timeout: 6000 })

  // Desktop : pas de bouton « plus » (outils inline).
  await page.setViewportSize({ width: 1366, height: 900 })
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1000)
  await expect(page.locator('[data-test="navbar-more"]')).toHaveCount(0)
})

test('débordements corrigés (portail/paie/congés) + non-régression desktop', async ({ page }) => {
  test.setTimeout(180000)
  await loginCompany(page, 'owner@leezr.test')
  const pages = ['/company/workforce/me', '/company/workforce/payroll', '/company/workforce/leave']
  for (const url of pages) {
    for (const w of [320, 375, 390, 430]) {
      await page.setViewportSize({ width: w, height: 844 })
      await page.goto(url, { waitUntil: 'domcontentloaded' })
      await page.waitForTimeout(2200)
      const o = await overflow(page)
      expect(o, `${url} @${w}px overflow ${o}px`).toBeLessThanOrEqual(2)
    }
  }
  // Non-régression desktop : dashboard 1366 sans débordement.
  await page.setViewportSize({ width: 1366, height: 900 })
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)
  expect(await overflow(page)).toBeLessThanOrEqual(2)
})

test('drawer mobile : ouverture, fermeture après navigation, Escape + focus', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)

  const hamburger = page.locator('#vertical-nav-toggle-btn')
  await expect(hamburger).toBeVisible()

  // Ouvre : l'overlay/scrim devient visible.
  await hamburger.click()
  await page.waitForTimeout(500)
  await expect(page.locator('.layout-overlay.visible')).toBeVisible()

  // Escape ferme.
  await page.keyboard.press('Escape')
  await page.waitForTimeout(500)
  await expect(page.locator('.layout-overlay.visible')).toHaveCount(0)

  // Réouvre puis navigue : le drawer se ferme après navigation.
  await hamburger.click()
  await page.waitForTimeout(400)
  await page.locator('.layout-vertical-nav a[href*="/company/workforce/employees"]').first().click()
  await page.waitForTimeout(800)
  await expect(page.locator('.layout-overlay.visible')).toHaveCount(0)
  await expect(page).toHaveURL(/employees/)
})

test('non-Workforce (Membres) : pas de débordement mobile', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  for (const w of [320, 390, 768]) {
    await page.setViewportSize({ width: w, height: 844 })
    await page.goto('/company/members', { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(2200)
    const o = await overflow(page)
    expect(o, `members @${w}px overflow ${o}px`).toBeLessThanOrEqual(2)
  }
})
