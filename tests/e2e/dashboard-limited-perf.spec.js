// @ts-check
// ADR-745 — capture dashboard utilisateur à PERMISSIONS LIMITÉES (driver) + mesure perf.
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const DIR = 'tests/e2e/__screens__/dashboard-p1-after'

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(2000)
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
  await page.waitForTimeout(700)
  await page.evaluate(() => window.scrollTo(0, 0))
  await page.waitForTimeout(1000)
}

test('dashboard — permissions limitées (driver bob)', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test', 'password')
  // Purge le cache client persistant (SWR) pour forcer une reconstruction fraîche du
  // smart-default éditorial filtré aux permissions du driver (évite un layout obsolète).
  await page.evaluate(() => { try { localStorage.removeItem('swr-cache'); sessionStorage.clear() } catch (e) { /* noop */ } })
  await page.setViewportSize({ width: 1440, height: 1600 })
  await page.goto('/dashboard')
  await settle(page)
  await page.screenshot({ path: `${DIR}/08-limited-driver-light.png` })
})

test('dashboard — performance réelle (owner)', async ({ page }) => {
  const timings = {}
  page.on('response', async resp => {
    const u = resp.url()
    if (u.includes('/dashboard/widgets/data') || u.includes('/dashboard/widgets/catalog')
      || u.includes('/dashboard/layout') || u.includes('/documents/compliance')) {
      const t = resp.request().timing()
      const key = u.split('/api/')[1]?.split('?')[0] || u
      timings[key] = Math.round((t.responseEnd - t.requestStart))
    }
  })

  await loginCompany(page)
  const t0 = Date.now()
  await page.goto('/dashboard')
  await page.waitForLoadState('networkidle').catch(() => {})
  // attendre que la grille soit peinte (au moins un widget)
  await page.locator('.dashboard-tile').first().waitFor({ timeout: 10000 }).catch(() => {})
  const domReady = Date.now() - t0
  await page.waitForTimeout(2500)

  console.log('=== PERF ===')
  console.log('dashboard interactive (ms):', domReady)
  console.log('API timings (ms):', JSON.stringify(timings, null, 2))
  const tiles = await page.locator('.dashboard-tile').count()
  console.log('tiles rendered:', tiles)
  console.log('=== END PERF ===')
})
