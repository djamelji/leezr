// Phase A/B — recette : item de nav « Planning », bandeau de couverture rétractable + boutons « + »
// visibles, bug d'ajout de tâche corrigé (préremplissage créneau libre → 201). Parcours UI réels.
import { test, expect } from '@playwright/test'
import { loginCompany, isolateInGrid, capableEmployee } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

async function api(page, m, u, b) {
  return await page.evaluate(async ({ m, u, b }) => {
    const x = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    const c = document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/)
    const r = await fetch(`/api${u}`, { method: m, credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': x ? decodeURIComponent(x[1]) : '', 'X-Company-Id': c ? decodeURIComponent(c[1]) : '' }, body: b ? JSON.stringify(b) : undefined })

    return { status: r.status, json: await r.json().catch(() => null) }
  }, { m, u, b })
}

test('navigation : item Planning présent', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1512, height: 982 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  // Item de nav « Planning » (manifest-driven, gated planning_view).
  await expect(page.locator('.layout-vertical-nav a', { hasText: /^Planning$/ }).first()).toBeVisible()
})

test('couverture : bandeau rétractable + boutons + visibles', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1512, height: 982 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1200)

  const linesOpen = await page.locator('tr.coverage-row:not(.coverage-toggle)').count()
  expect(linesOpen).toBeGreaterThan(0)
  // Rétracter → lignes par catégorie masquées (synthèse seule).
  await page.locator('[data-test="cov-collapse"]').click()
  await page.waitForTimeout(400)
  expect(await page.locator('tr.coverage-row:not(.coverage-toggle):visible').count()).toBe(0)
  // Rouvrir.
  await page.locator('[data-test="cov-collapse"]').click()
  await page.waitForTimeout(300)
  expect(await page.locator('tr.coverage-row:not(.coverage-toggle):visible').count()).toBe(linesOpen)
  // Boutons « + » présents dans les cellules vides (ajout de BESOIN, pas de shift).
  expect(await page.locator('.cov-add-btn').count()).toBeGreaterThan(0)
})

test('ajout de tâche : préremplissage créneau libre, pas d\'erreur (bug corrigé)', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1512, height: 982 })

  let weekDate = null
  page.on('request', r => { const mm = r.url().match(/\/shifts\/week\?date=([\d-]+)/); if (mm) weekDate = mm[1] })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1200)
  expect(weekDate).toBeTruthy()

  // Précondition DÉTERMINISTE (M6.E) : employé capable d'un shift 09:00–12:00 (durée conforme) + shift propre
  // SANS activité → créneaux libres garantis pour le préremplissage.
  const employee = await capableEmployee(page, weekDate, '09:00:00', '12:00:00')
  test.skip(!employee, 'Aucun employé capable sur la base E2E.')
  const mk = await api(page, 'POST', '/workforce/planning/shifts', { employee_id: employee.id, date: weekDate, start_at: `${weekDate} 09:00:00`, end_at: `${weekDate} 12:00:00`, timezone: 'Europe/Paris' })
  expect(mk.status).toBe(201)
  await page.reload({ waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1000)
  await isolateInGrid(page, employee.name)

  await page.locator('.plan-shift__body').first().click({ force: true })
  await page.waitForTimeout(700)
  const dlg = page.locator('.v-dialog').last()
  // Deux ajouts successifs : chacun préremplit le premier créneau LIBRE → 201 (plus de 422 « hors bornes »).
  for (let i = 0; i < 2; i++) {
    await dlg.locator('[data-test="activity-add"]').click()
    await page.waitForTimeout(300)
    expect(await dlg.getByLabel('Début').inputValue()).toMatch(/^\d{2}:\d{2}$/) // prérempli
    await dlg.getByLabel('Libellé').fill(`Prestation ${i}`)
    const [resp] = await Promise.all([
      page.waitForResponse(r => /\/planning\/activities$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
      dlg.locator('[data-test="activity-submit"]').click(),
    ])
    expect(resp.status()).toBe(201)
    await page.waitForTimeout(500)
  }
})
