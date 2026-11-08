// @ts-check
// Phase 0 — accessibilité de la page de référence (axe-core).
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginCompany } from './helpers.js'

test('ui-lab — accessibility (no serious/critical violations)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page)
  await page.goto('/ui-lab')
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(2500)

  // Scope : uniquement le CONTENU de la page (couche Leezr), pas le shell Vuexy
  // (navbar/sidebar = @core/@layouts, non modifiables par la loi UI).
  const results = await new AxeBuilder({ page })
    .include('.layout-page-content')
    .exclude('.layout-navbar')
    .exclude('.layout-vertical-nav')
    .withTags(['wcag2a', 'wcag2aa'])
    .analyze()

  const serious = results.violations.filter(v => ['serious', 'critical'].includes(v.impact))
  for (const v of serious) console.log(`A11Y ${v.impact}: ${v.id} — ${v.help} (${v.nodes.length} node(s))`)
  console.log(`A11Y total violations: ${results.violations.length} | serious/critical: ${serious.length}`)
  expect(serious.length, 'serious/critical a11y violations').toBe(0)
})
