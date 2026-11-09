// @ts-check
// ADR-745 — a11y du dashboard Company refondu (axe), light + dark.
// Scope : contenu de page uniquement (hors nav/navbar, non modifiés — @core/@layouts).
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginCompany } from './helpers.js'

async function run(page) {
  await page.goto('/dashboard')
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(3500)
  const results = await new AxeBuilder({ page })
    .include('.layout-page-content')
    .exclude('.layout-navbar')
    .exclude('.layout-vertical-nav')
    .analyze()
  return results.violations.filter(v => v.impact === 'serious' || v.impact === 'critical')
}

test('dashboard a11y — 0 serious/critical (light + dark)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await loginCompany(page)

  const light = await run(page)
  console.log('LIGHT serious/critical:', light.length, light.map(v => v.id).join(', '))

  await page.emulateMedia({ colorScheme: 'dark' })
  const dark = await run(page)
  console.log('DARK serious/critical:', dark.length, dark.map(v => v.id).join(', '))

  expect(light.length, 'light serious/critical').toBe(0)
  expect(dark.length, 'dark serious/critical').toBe(0)
})
