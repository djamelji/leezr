// Refonte grille hebdo (ADR-713) : blocs métier (nom de poste), vue par postes, résumé heures/employé,
// vue jour mobile. Parcours UI réels + persistance.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

async function api(page, m, u, b) {
  return await page.evaluate(async ({ m, u, b }) => {
    const x = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    const c = document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/)
    const r = await fetch(`/api${u}`, { method: m, credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': x ? decodeURIComponent(x[1]) : '', 'X-Company-Id': c ? decodeURIComponent(c[1]) : '' }, body: b ? JSON.stringify(b) : undefined })

    return { status: r.status, json: await r.json().catch(() => null) }
  }, { m, u, b })
}

test('desktop : blocs métier + résumé heures + bascule vue par postes', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1512, height: 982 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1200)

  // Blocs métier : le poste (nom) est affiché, pas seulement les heures.
  await expect(page.locator('.plan-shift__poste').first()).toBeVisible()
  // Résumé par collaborateur (heures · jours).
  await expect(page.getByText(/\d+(\.\d+)?h · \d+ j/).first()).toBeVisible()

  // Bascule vue par postes : lignes par catégorie + collaborateurs nominatifs.
  await page.locator('[data-test="view-posts"]').click()
  await page.waitForTimeout(600)
  await expect(page.locator('.plan-post-chip').first()).toBeVisible()
  // Retour vue par salariés.
  await page.locator('[data-test="view-employees"]').click()
  await page.waitForTimeout(300)
  await expect(page.locator('.plan-shift__poste').first()).toBeVisible()
})

test('mobile : Planning accessible + vue jour avec blocs métier', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)
  // L'onglet Planning est désormais disponible sur mobile.
  const planTab = page.locator('[data-test="plan-view-planning"]')
  await expect(planTab).toBeVisible()
  await planTab.click()
  await page.waitForTimeout(1200)
  // Vue jour : des blocs métier (poste) sont listés, pas une grille compressée.
  await expect(page.locator('.plan-shift__poste').first()).toBeVisible()
})
