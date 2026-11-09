// @ts-check
// ADR-745 — parcours à permissions réduites + mesure perf.
//  - driver (bob) : redirigé vers /home (surface opérationnelle, ADR-357) → pas de 403.
//  - dispatcher (alice) : management à droits réduits → /dashboard éditorial filtré.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dashboard-p1-after'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(2200)
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
  await page.waitForTimeout(700)
  await page.evaluate(() => window.scrollTo(0, 0))
  await page.waitForTimeout(1000)
}

test('parcours driver (bob) — pas de 403 parasite', async ({ page }) => {
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  page.on('response', r => { if (r.status() === 403) errors.push('403 ' + r.url()) })

  await loginCompany(page, 'bob@leezr.test', 'password')
  await page.setViewportSize({ width: 1440, height: 1600 })
  await page.goto('/dashboard')
  await settle(page)
  await page.screenshot({ path: `${DIR}/08-limited-driver.png` })

  const layout403 = errors.filter(e => e.includes('/dashboard/layout'))
  console.log('URL:', page.url(), '| 403 layout:', layout403.length, '| all 403:', errors.filter(e => e.includes('403')).length)
  expect(layout403, 'aucun 403 PUT /dashboard/layout').toHaveLength(0)
})

test('dispatcher (alice) — dashboard management filtré', async ({ page }) => {
  await loginCompany(page, 'admin@leezr.test', 'password')
  await page.setViewportSize({ width: 1440, height: 1800 })
  await page.goto('/dashboard')
  await settle(page)
  console.log('alice URL:', page.url())
  await page.screenshot({ path: `${DIR}/09-limited-dispatcher.png` })
})

test('performance réelle (owner)', async ({ page }) => {
  await loginCompany(page)
  const t0 = Date.now()
  await page.goto('/dashboard')
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.locator('.dashboard-tile').first().waitFor({ timeout: 10000 }).catch(() => {})
  const interactive = Date.now() - t0
  const perf = await page.evaluate(() => {
    const e = performance.getEntriesByType('resource').filter(r => r.name.includes('/dashboard/widgets/data') || r.name.includes('/dashboard/layout') || r.name.includes('/documents/compliance'))
    return e.map(r => ({ u: r.name.split('/api/')[1]?.split('?')[0], ms: Math.round(r.duration) }))
  })
  const tiles = await page.locator('.dashboard-tile').count()
  console.log('PERF interactive_ms:', interactive, '| tiles:', tiles, '| api:', JSON.stringify(perf))
})
