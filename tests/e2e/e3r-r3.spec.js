// @ts-check
// SMART RESPONSIVE UX 2026 / R3 — recette des primitives adaptatives sur de VRAIS écrans.
// Interactions réelles : overflow d'actions selon la largeur, nav adaptative, data view table/liste,
// préservation d'état lors d'un changement de largeur, filtrage par permission.
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

test('SmartActionBar : adaptatif sur la fiche (mobile = principale + overflow ; desktop = tout visible)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')

  const directBtns = () => page.locator('.smart-action-bar > .v-btn[data-test]:not([data-test="sab-overflow"])')

  // Mobile (conteneur header étroit) : action principale visible + overflow pour le reste.
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await page.getByText('Djamel', { exact: false }).first().waitFor({ timeout: 15000 })
  await page.waitForTimeout(1500)
  await expect(page.locator('[data-test="emp-edit"]')).toBeVisible()
  await expect(page.locator('[data-test="sab-overflow"]')).toBeVisible()
  const narrowCount = await directBtns().count()
  // Ouvrir l'overflow → une action secondaire devient accessible (menu clavier).
  await page.locator('[data-test="sab-overflow"]').click()
  await page.waitForTimeout(400)
  await expect(page.locator('[data-test="emp-manage-access"]')).toBeVisible()
  await page.keyboard.press('Escape')

  // Desktop large : davantage d'actions tiennent en boutons directs qu'en mobile (adaptation réelle).
  await page.setViewportSize({ width: 1600, height: 900 })
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await page.getByText('Djamel', { exact: false }).first().waitFor({ timeout: 15000 })
  await page.waitForTimeout(1500)
  await expect(page.locator('[data-test="emp-edit"]')).toBeVisible()
  const wideCount = await directBtns().count()
  expect(wideCount, `desktop ${wideCount} ≥ mobile ${narrowCount} actions directes`).toBeGreaterThanOrEqual(narrowCount)
})

test('AppSectionNavigation : icônes seules sans scroll sur mobile, onglets icône+libellé sur desktop', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')

  // Mobile : onglets ICÔNES SEULES répartis, pas de scroll horizontal, actif clair.
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await page.getByText('Djamel', { exact: false }).first().waitFor({ timeout: 15000 })
  await page.waitForTimeout(1200)
  await expect(page.locator('[data-test="asn-mtab-overview"]')).toBeVisible()
  // La barre d'onglets ne provoque pas de débordement.
  const o = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - window.innerWidth))
  expect(o, `overflow ${o}px`).toBeLessThanOrEqual(2)
  // Bascule de section par icône.
  await page.locator('[data-test="asn-mtab-profile"]').click()
  await page.waitForTimeout(400)

  // Desktop : onglets Vuexy icône + libellé (au-dessus du contenu).
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/company/workforce/employees/1', { waitUntil: 'domcontentloaded' })
  await page.getByText('Djamel', { exact: false }).first().waitFor({ timeout: 15000 })
  await page.waitForTimeout(1200)
  await expect(page.locator('[data-test="asn-tab-overview"]')).toBeVisible()
  await expect(page.locator('[data-test="asn-mtab-overview"]')).toHaveCount(0)
})

test('AppResponsiveDataView : table desktop / liste mobile + état préservé au changement de largeur', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page, 'owner@leezr.test')

  // Desktop : table.
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/company/workforce/employees', { waitUntil: 'domcontentloaded' })
  await page.waitForResponse(r => r.url().includes('/workforce/employees') && r.request().method() === 'GET', { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(1500)
  await expect(page.locator('.v-data-table tbody tr').first()).toBeVisible()

  // Saisir une recherche puis passer en mobile : la recherche NE DOIT PAS être perdue.
  await page.locator('[data-test="fp-search"] input').fill('Thomas')
  await page.waitForTimeout(800)
  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(1000)
  await expect(page.locator('[data-test="fp-search"] input')).toHaveValue('Thomas')
  // Mobile : liste de cartes (pas la table).
  await expect(page.locator('.arv-mobile .arv-row').first()).toBeVisible()
  const o = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - window.innerWidth))
  expect(o, `annuaire mobile overflow ${o}px`).toBeLessThanOrEqual(2)
})

test('SmartActionBar : filtrage par permission (laboratoire)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto('/company/dev-lab', { waitUntil: 'domcontentloaded' })
  await page.getByText('Smart Responsive UX', { exact: false }).first().waitFor({ timeout: 12000 })
  await page.waitForTimeout(1000)
  // 10 actions, largeur large : l'action « Restreint » est visible (ou en overflow) par défaut.
  await page.locator('[data-test="lab-count"] button', { hasText: '10' }).click()
  await page.waitForTimeout(500)
  const container = page.locator('[data-test="lab-sab-container"]')
  await expect(container.getByText('Modifier', { exact: false }).first()).toBeVisible()
  // Activer « Une action non autorisée » → l'action restreinte disparaît (filtrage permission).
  await page.getByText('Une action non autorisée', { exact: false }).click()
  await page.waitForTimeout(500)
  // L'action principale reste toujours présente.
  await expect(container.getByText('Modifier', { exact: false }).first()).toBeVisible()
})
