// @ts-check — a11y du dashboard Platform P2 (ADR-746). Scope = contenu de page ;
// exclusions = chrome @core (nav, TheCustomizer) non modifiable.
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginPlatform } from './helpers.js'
async function run(page){
  await page.goto('/platform/dashboard/overview'); await page.waitForLoadState('networkidle').catch(()=>{}); await page.waitForTimeout(3500)
  const r = await new AxeBuilder({page}).include('.layout-page-content')
    .exclude('.layout-navbar').exclude('.layout-vertical-nav')
    .exclude('.app-customizer').exclude('.app-customizer-toggler').analyze()
  return r.violations.filter(v=>['serious','critical'].includes(v.impact))
}
test('platform P2 a11y — 0 serious (light+dark)', async ({ page }) => {
  await page.setViewportSize({width:1440,height:1100}); await loginPlatform(page)
  const light = await run(page); console.log('LIGHT:', light.length, light.map(v=>v.id).join(','))
  await page.emulateMedia({colorScheme:'dark'}); const dark = await run(page); console.log('DARK:', dark.length, dark.map(v=>v.id).join(','))
  expect(light.length, 'light').toBe(0); expect(dark.length, 'dark').toBe(0)
})
