// @ts-check
// ADR-782 (UX6.1) — tiroirs harmonisés : capture desktop et mobile (ouverture seule, aucune écriture).
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/ux6-782'
const shots = [
  ['shift', '/company/workforce/planning', '[data-test="shift-add"], [data-test="mobile-new-shift"]'],
  ['leave', '/company/workforce/leave', '[data-test="leave-new"]'],
  ['me-leave', '/company/workforce/me', '[data-test="me-request-leave"]'],
]
for (const [label, w, h] of [['desktop', 1440, 900], ['mobile', 390, 844]]) {
  test(`tiroirs ${label}`, async ({ page }) => {
    test.setTimeout(180000)
    const bad = []
    page.on('pageerror', e => bad.push(String(e.message)))
    await page.setViewportSize({ width: w, height: h })
    await loginCompany(page, 'owner@leezr.test')
    for (const [key, url, opener] of shots) {
      await page.goto(url, { waitUntil: 'networkidle' })
      await page.waitForTimeout(1000)
      const btn = page.locator(opener).filter({ visible: true }).first()
      if (!(await btn.count())) continue
      await btn.click()
      await page.waitForTimeout(700)
      await expect(page.locator('.app-drawer .app-drawer-actions').first()).toBeVisible()
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(w)
      await page.screenshot({ path: `${OUT}/after61-drawer-${key}-${label}.jpg`, type: 'jpeg', quality: 60 })
    }
    expect(bad).toEqual([])
  })
}
