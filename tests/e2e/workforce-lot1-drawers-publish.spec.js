// Lot 1 — recette : scroll des drawers Workforce réparé (multi-résolution) + publication du planning
// (aperçu → confirmation → état publié persisté). Parcours UI réels.
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

test('drawers : scroll fonctionnel jusqu\'aux actions, multi-résolution', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  for (const vp of [{ w: 1920, h: 1080 }, { w: 1366, h: 768 }, { w: 1280, h: 720 }]) {
    await page.setViewportSize({ width: vp.w, height: vp.h })
    await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
    await page.locator('[data-test="plan-view-planning"]').click()
    await page.waitForTimeout(900)
    await page.getByRole('button', { name: /Besoins de personnel/i }).click()
    await page.waitForTimeout(500)
    const drawer = page.locator('.v-navigation-drawer.wf-scroll-drawer', { has: page.getByRole('heading', { name: 'Besoins de personnel' }) })
    // Le conteneur scrolle réellement (overflow-y auto, restauré malgré le défaut Vuexy).
    const ov = await drawer.evaluate(el => getComputedStyle(el.querySelector('.v-navigation-drawer__content')).overflowY)
    expect(ov).toBe('auto')
    // On atteint le bouton d'action du bas par scroll.
    await drawer.locator('.v-navigation-drawer__content').evaluate(c => c.scrollTo(0, c.scrollHeight))
    await page.waitForTimeout(200)
    await expect(drawer.locator('[data-test="coverage-submit"]')).toBeVisible()
    // Fermeture : le scroll de la page n'est pas bloqué.
    await page.keyboard.press('Escape')
    await page.waitForTimeout(300)
    const locked = await page.evaluate(() => getComputedStyle(document.body).overflow === 'hidden' && document.body.scrollHeight > window.innerHeight)
    expect(locked).toBeFalsy()
  }
})

test('publication : aperçu → confirmation → état publié persisté', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await page.setViewportSize({ width: 1512, height: 982 })
  let wk = null
  page.on('request', r => { const mm = r.url().match(/\/shifts\/week\?date=([\d-]+)/); if (mm) wk = mm[1] })
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1000)

  // Shift brouillon frais à une HEURE UNIQUE (évite les résidus → cible non ambiguë).
  const mm = String(5 + (Date.now() % 50)).padStart(2, '0')
  const hhmm = `05:${mm}`
  const emps = await api(page, 'GET', '/workforce/employees?per_page=20')
  const list = (emps.json.data ?? emps.json)
  let shiftId = null
  for (const e of list) {
    const s = await api(page, 'POST', '/workforce/planning/shifts', { employee_id: e.id, date: wk, start_at: `${wk} ${hhmm}:00`, end_at: `${wk} 06:59:00`, timezone: 'Europe/Paris' })
    if (s.status === 201) { shiftId = s.json.id; break }
  }
  expect(shiftId).toBeTruthy()

  await page.reload({ waitUntil: 'networkidle' })
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(1200)

  // Sélectionne le shift brouillon (checkbox) → bouton « Publier la sélection ».
  const cell = page.locator('.plan-shift', { hasText: hhmm }).first()
  await expect(cell.locator('.plan-shift__check input')).toHaveCount(1)
  await cell.hover()
  await cell.locator('.plan-shift__check input').click({ force: true })
  await page.waitForTimeout(300)
  await page.locator('[data-test="publish-selection"]').click()
  await page.waitForTimeout(400)

  // Dialog de confirmation avec aperçu.
  const dlg = page.locator('.v-dialog').filter({ hasText: /Publier le planning|Publish schedule/ })
  await expect(dlg).toBeVisible()
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/shifts\/publish$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    dlg.locator('[data-test="publish-confirm"]').click(),
  ])
  expect(resp.status()).toBe(200)

  // Persistance : le shift est publié (relecture via l'endpoint semaine).
  const check = await api(page, 'GET', `/workforce/planning/shifts/week?date=${wk}`)
  const published = (check.json.data ?? check.json).find(s => s.id === shiftId)
  expect(published).toBeTruthy()
  expect(published.status).toBe('published')
})
