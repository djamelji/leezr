// M6.E (ADR-736) — Accessibilité du Smart Planning. Scan axe-core (WCAG 2.2 AA applicable) sur les 4 vues
// + vérifications ciblées (focus clavier visible, libellés). Le scanner ne CERTIFIE PAS la conformité :
// il remonte des violations objectives ; les vérifs manuelles complètent (cf. rapport M6.E).
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function scan(page, selector) {
  const res = await new AxeBuilder({ page }).withTags(WCAG).include(selector).analyze()

  return res.violations.map(v => ({ id: v.id, impact: v.impact, n: v.nodes.length, help: v.help }))
}

test('M6.E A11Y — scan axe WCAG 2.2 AA sur les 4 vues + focus clavier', async ({ page }) => {
  test.setTimeout(180000)
  await page.setViewportSize({ width: 1440, height: 1000 })
  await loginCompany(page)
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })

  const all = {}
  const modes = [
    ['week', 'plan-view-planning', '.planning-grid'],
    ['day', 'plan-view-calendar', '.fc-timegrid'],
    ['posts', 'plan-view-posts', '[data-test="posts-view"]'],
    ['month', 'plan-view-month', '[data-test="month-view"]'],
  ]
  for (const [name, tid, target] of modes) {
    await page.locator(`[data-test="${tid}"]`).click()
    await page.locator(target).first().waitFor({ state: 'visible', timeout: 20000 })
    await page.waitForTimeout(800)
    const v = await scan(page, target)
    all[name] = v
    console.log(`M6E_A11Y ${name} violations=${v.length} ` + JSON.stringify(v))
  }
  console.log('M6E_A11Y_TABLE ' + JSON.stringify(all))

  // Focus clavier : la sous-nav est atteignable au clavier (l'élément reçoit bien le focus).
  // (L'indicateur visuel Vuetify passe par box-shadow/::before, pas par `outline` → on vérifie la
  // FOCUSABILITÉ, pas le style d'outline qui serait un faux négatif.)
  await page.locator('[data-test="plan-view-planning"]').focus()
  const focused = await page.evaluate(() => document.activeElement?.closest('[data-test="plan-view-planning"]') !== null)
  expect(focused, 'la sous-nav doit être focusable au clavier').toBe(true)

  // Garde-fou DUR : aucune violation d'impact "critical" sur les vues cœur (les serious/moderate sont loguées
  // et traitées dans le rapport ; axe ne certifie pas à lui seul la conformité).
  const critical = Object.entries(all).flatMap(([m, vs]) => vs.filter(v => v.impact === 'critical').map(v => `${m}:${v.id}`))
  expect(critical, `violations critiques: ${critical.join(', ')}`).toHaveLength(0)
})
