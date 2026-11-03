// @ts-check
// ADR-690 / E2 — captures de recette (application réelle, build 4c17dea).
// Produit, par profil, la sidebar en desktop (light), dark, et le drawer mobile 390.
// Ces captures alimentent l'artefact visuel de recette E2.
import { mkdirSync } from 'fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers'

// Dossier de sortie des captures ; surchargeable via E2_CAPTURE_DIR.
const OUT = process.env.E2_CAPTURE_DIR || '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/e2-artefact'
mkdirSync(OUT, { recursive: true })
const PW = 'password'

const PROFILES = [
  'owner@leezr.test',
  'rh-nopay@leezr.test',
  'manager@leezr.test',
  'ops@leezr.test',
  'consultation@leezr.test',
  'pay@leezr.test',
  'salarie@leezr.test',
]

for (const email of PROFILES) {
  const slug = email.split('@')[0]
  test(`captures ${slug}`, async ({ page }) => {
    await loginCompany(page, email, PW)

    // Desktop light — sidebar.
    await page.setViewportSize({ width: 1440, height: 980 })
    await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' }).catch(() => {})
    // Les profils sans workforce.view sont redirigés ; retomber sur /dashboard pour avoir la sidebar.
    if (!page.url().includes('/company/workforce/employees'))
      await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    const nav = page.locator('.layout-vertical-nav').first()
    await nav.waitFor({ state: 'visible', timeout: 10000 })
    await page.waitForTimeout(800)
    await nav.screenshot({ path: `${OUT}/${slug}-desktop.png` })

    // Dark — le thème par défaut est « system » : on émule prefers-color-scheme: dark + reload.
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.reload({ waitUntil: 'domcontentloaded' })
    const navDark = page.locator('.layout-vertical-nav').first()
    await navDark.waitFor({ state: 'visible', timeout: 10000 })
    await page.waitForTimeout(1000)
    await navDark.screenshot({ path: `${OUT}/${slug}-dark.png` })
    // Revenir en clair pour les captures suivantes.
    await page.emulateMedia({ colorScheme: 'light' })

    // Contexte plein écran (owner + rh-nopay) pour montrer la nav dans sa page — en clair.
    if (slug === 'owner' || slug === 'rh-nopay') {
      await page.reload({ waitUntil: 'domcontentloaded' })
      await page.locator('.layout-vertical-nav').first().waitFor({ state: 'visible', timeout: 10000 })
      await page.waitForTimeout(800)
      await page.screenshot({ path: `${OUT}/${slug}-fullpage.png`, fullPage: false })
    }

    // Mobile 390 — drawer ouvert.
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(600)
    const toggle = page.locator('.header-action .tabler-menu-2, i.tabler-menu-2, .nav-toggle').first()
    await toggle.click({ timeout: 8000 }).catch(() => {})
    await page.waitForTimeout(800)
    await page.screenshot({ path: `${OUT}/${slug}-mobile.png`, fullPage: false })
  })
}
