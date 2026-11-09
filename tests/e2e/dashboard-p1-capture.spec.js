// @ts-check
// P1/ADR-745 — captures du VRAI dashboard Company (read-only).
// Viewport HAUT + screenshot normal (pas fullPage) : le fullPage de Playwright ne
// peint pas les SVG ApexCharts sous la ligne de flottaison → charts blancs. Un
// viewport assez haut place tout « in-viewport » et un passage de scroll force la peinture.
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dashboard-p1-after'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(1500)
  // Force la peinture de tout le contenu (charts inclus) : scroll bas puis haut.
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
  await page.waitForTimeout(800)
  await page.evaluate(() => window.scrollTo(0, 0))
  await page.waitForTimeout(1200)
}

async function shoot(page, w, h, path) {
  await page.setViewportSize({ width: w, height: h })
  await page.goto('/dashboard')
  await settle(page)
  await page.screenshot({ path }) // viewport entier (haut) — pas fullPage
}

test('company dashboard — après P1 (tous viewports/thèmes)', async ({ page }) => {
  await loginCompany(page)

  await shoot(page, 1440, 2400, `${DIR}/01-desktop-light.png`)

  await page.emulateMedia({ colorScheme: 'dark' })
  await shoot(page, 1440, 2400, `${DIR}/02-desktop-dark.png`)
  await page.emulateMedia({ colorScheme: 'light' })

  await shoot(page, 834, 2600, `${DIR}/03-tablet-light.png`)

  await shoot(page, 390, 3200, `${DIR}/04-mobile-light.png`)

  await page.emulateMedia({ colorScheme: 'dark' })
  await shoot(page, 390, 3200, `${DIR}/05-mobile-dark.png`)
  await page.emulateMedia({ colorScheme: 'light' })
})
