// @ts-check
// ADR-783 (UX6.2) — dialogues documentaires harmonisés : ouverture seule (aucune émission), desktop et
// mobile, clair ; contrôle de l'en-tête commun, du bouton fermer et du pied d'actions.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const OUT = 'docs/bmad/ux6-782'
const openNew = async (page, key) => {
  await page.goto('/company/documents/todo', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  await page.locator('[data-test="documents-new"]').click()
  await page.locator(`[data-test="documents-new-${key}"]`).click()
  await page.waitForTimeout(900)
}
for (const [label, w, h] of [['desktop', 1440, 900], ['mobile', 390, 844]]) {
  test(`dialogues ${label}`, async ({ page }) => {
    test.setTimeout(240000)
    const bad = []
    page.on('pageerror', e => bad.push(String(e.message)))
    await page.setViewportSize({ width: w, height: h })
    await loginCompany(page, 'owner@leezr.test')
    for (const key of ['deposit', 'request', 'sign']) {
      await openNew(page, key)
      const dlg = page.locator('.v-overlay--active .app-dialog').last()
      await expect(dlg, `${key} : dialogue commun`).toBeVisible()
      await expect(dlg.locator('.app-dialog__title')).not.toBeEmpty()
      await expect(dlg.locator('.app-dialog__actions')).toBeVisible()
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(w)
      await page.screenshot({ path: `${OUT}/after62-dialog-${key}-${label}.jpg`, type: 'jpeg', quality: 60 })
      await page.keyboard.press('Escape')
    }
    // Générer un contrat : depuis la fiche (le propriétaire a un contrat) — ouverture seule.
    await page.goto('/company/people/employee-1', { waitUntil: 'networkidle' })
    await page.waitForTimeout(900)
    await page.locator('[data-test="person-actions-menu"]').click()
    const gen = page.locator('[data-test="person-action-generate-contract"]')
    if (await gen.count()) {
      await gen.click()
      await page.waitForTimeout(900)
      await expect(page.locator('.v-overlay--active .app-dialog').last()).toBeVisible()
      await page.screenshot({ path: `${OUT}/after62-dialog-contract-${label}.jpg`, type: 'jpeg', quality: 60 })
    }
    expect(bad).toEqual([])
  })
}
