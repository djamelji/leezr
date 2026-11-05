// SP1a.1 — recette E2E : grille → timeline journalière → activité persistée (parcours UI réel), copie de
// semaine. Le fix ShiftReadModel::forWeek (whereDate) rend le shift fraîchement créé visible dans la grille.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

async function api(page, method, url, body) {
  return await page.evaluate(async ({ method, url, body }) => {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    const c = document.cookie.match(/(?:^|; )currentCompanyId=([^;]+)/)
    const res = await fetch(`/api${url}`, {
      method, credentials: 'include',
      headers: {
        Accept: 'application/json', 'Content-Type': 'application/json',
        'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '', 'X-Company-Id': c ? decodeURIComponent(c[1]) : '',
      },
      body: body ? JSON.stringify(body) : undefined,
    })

    return { status: res.status, json: await res.json().catch(() => null) }
  }, { method, url, body })
}

test('grille → timeline → activité persistée (parcours UI)', async ({ page }) => {
  test.setTimeout(150000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1100 })

  let weekDate = null
  page.on('request', r => { const mm = r.url().match(/\/shifts\/week\?date=([\d-]+)/); if (mm) weekDate = mm[1] })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1000)
  expect(weekDate).toBeTruthy()

  const emp = await api(page, 'GET', '/workforce/employees?per_page=1')
  const employeeId = (emp.json.data ?? emp.json)[0].id

  // Shift dans la semaine affichée (réutilise si résidu).
  const shift = await api(page, 'POST', '/workforce/planning/shifts', {
    employee_id: employeeId, date: weekDate,
    start_at: `${weekDate} 09:00:00`, end_at: `${weekDate} 18:00:00`, timezone: 'Europe/Paris',
  })
  if (shift.status === 422) {
    const ex = await api(page, 'GET', `/workforce/planning/shifts?from=${weekDate}&to=${weekDate}&employee_id=${employeeId}`)
    const s = (ex.json.data ?? ex.json)[0]
    const acts = await api(page, 'GET', `/workforce/planning/activities?shift_id=${s.id}`)
    for (const a of (acts.json ?? [])) await api(page, 'DELETE', `/workforce/planning/activities/${a.id}`)
  }

  await page.reload({ waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1200)

  // Le shift est visible dans la grille (fix forWeek) → ouvrir le détail journalier (clic sur le shift).
  await expect(page.locator('.plan-shift__body').first()).toBeVisible({ timeout: 10000 })
  await page.locator('.plan-shift__body').first().click({ force: true })
  await page.waitForTimeout(500)

  const dialog = page.locator('.v-dialog').filter({ hasText: /Timeline|activité/i })

  // Révèle le formulaire d'ajout (replié par défaut dans la timeline refondue).
  await dialog.locator('[data-test="activity-add"]').click()
  await page.waitForTimeout(300)

  // Ajoute une activité via l'UI → POST 201 persisté.
  await dialog.getByLabel('Libellé').fill('Coupe')
  await dialog.getByLabel('Début').fill('09:00')
  await dialog.getByLabel('Fin').fill('10:00')
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/planning\/activities$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    dialog.locator('[data-test="activity-submit"]').click(),
  ])
  expect(resp.status()).toBe(201)
  await expect(dialog.getByText('Coupe', { exact: false }).first()).toBeVisible()
  await page.keyboard.press('Escape')
  await page.waitForTimeout(300)

  // Persistance après rechargement : l'activité est toujours là.
  await page.reload({ waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1200)
  await page.locator('.plan-shift__body').first().click({ force: true })
  await page.waitForTimeout(600)
  await expect(page.locator('.v-dialog').filter({ hasText: /Timeline|activité/i }).getByText('Coupe', { exact: false }).first()).toBeVisible()
})

test('copie de la semaine précédente (brouillons)', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1440, height: 1100 })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(800)

  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/shifts\/copy-week$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="copy-prev-week"]').click(),
  ])
  expect(resp.status()).toBe(200)
  const body = await resp.json()
  expect(body).toHaveProperty('created')
  expect(body).toHaveProperty('skipped')
})
