// @ts-check
// Phase 0 — accessibilité : page de référence + page réelle (primary AA).
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginCompany } from './helpers.js'

async function scan(page, scheme) {
  await page.emulateMedia({ colorScheme: scheme })
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(2000)
  const res = await new AxeBuilder({ page })
    .include('.layout-page-content')
    .exclude('.layout-navbar').exclude('.layout-vertical-nav')
    .withTags(['wcag2a', 'wcag2aa']).analyze()
  const serious = res.violations.filter(v => ['serious', 'critical'].includes(v.impact))
  const contrast = serious.filter(v => v.id === 'color-contrast')
  console.log(`A11Y ${scheme}: serious=${serious.length} (dont color-contrast ${contrast.reduce((n, v) => n + v.nodes.length, 0)} nœuds)`)
  for (const v of serious) console.log(`   ${v.impact} ${v.id} (${v.nodes.length})`)

  return serious.length
}

test('ui-lab — a11y light & dark', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page)
  await page.goto('/ui-lab')
  const l = await scan(page, 'light')
  const d = await scan(page, 'dark')
  expect(l + d, 'serious a11y (ui-lab light+dark)').toBe(0)
})

test('dashboard réel — contraste primary AA light & dark', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginCompany(page)
  await page.goto('/dashboard')
  // On ne mesure QUE le contraste (le reste du dashboard legacy peut avoir d'autres dettes)
  async function contrastOnly(scheme) {
    await page.emulateMedia({ colorScheme: scheme })
    await page.waitForTimeout(2000)
    const res = await new AxeBuilder({ page }).include('.layout-page-content')
      .exclude('.layout-navbar').exclude('.layout-vertical-nav')
      .withRules(['color-contrast']).analyze()
    const n = res.violations.reduce((a, v) => a + v.nodes.length, 0)
    console.log(`DASHBOARD color-contrast ${scheme}: ${n} nœud(s)`)

    return n
  }
  const l = await contrastOnly('light')
  const d = await contrastOnly('dark')
  // Seuil informatif : on veut 0 sur les éléments primary ; on logge le détail.
  expect(l + d, 'contraste dashboard light+dark').toBeLessThanOrEqual(0)
})
