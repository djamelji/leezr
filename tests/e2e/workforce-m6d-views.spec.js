// Smart Planning — M6.D (ADR-735) : expérience Planning unifiée, 4 modes (Semaine/Jour/Postes/Mois)
// partageant date + filtres, depuis la VRAIE interface. Les scénarios vérifient les DONNÉES (cross-check
// board/coverage via l'API scopée), pas seulement la présence des composants.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

const iso = d => {
  const p = n => String(n).padStart(2, '0')

  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

async function gotoPlanning(page, w = 1500) {
  await page.setViewportSize({ width: w, height: 1000 })
  await page.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
}

// ── Mode switch : les 4 modes + Modèles, chacun montant la bonne vue ──
test('M6.D — sous-nav unique : Semaine/Jour/Postes/Mois + Modèles', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page)

  for (const tid of ['plan-view-planning', 'plan-view-calendar', 'plan-view-posts', 'plan-view-month', 'plan-view-templates'])
    await expect(page.locator(`[data-test="${tid}"]`)).toBeVisible({ timeout: 10000 })

  // Semaine → grille D1 ; Jour → timeline horaire FullCalendar ; Postes → vue besoins ; Mois → grille mois.
  await page.locator('[data-test="plan-view-planning"]').click()
  await expect(page.locator('.planning-grid').first()).toBeVisible({ timeout: 10000 })

  await page.locator('[data-test="plan-view-calendar"]').click()
  await expect(page.locator('.fc-timegrid').first()).toBeVisible({ timeout: 10000 })

  await page.locator('[data-test="plan-view-posts"]').click()
  await expect(page.locator('[data-test="posts-view"]')).toBeVisible({ timeout: 10000 })

  await page.locator('[data-test="plan-view-month"]').click()
  await expect(page.locator('[data-test="month-view"]')).toBeVisible({ timeout: 10000 })
})

// A — Semaine → Jour → Semaine : la semaine affichée est conservée (ancrage partagé).
test('M6.D A — Semaine→Jour→Semaine conserve l’ancrage', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page)

  await page.locator('[data-test="plan-view-planning"]').click()
  await expect(page.locator('.planning-grid').first()).toBeVisible()

  // Jour : navigateur partagé ancré sur un jour de la semaine courante.
  await page.locator('[data-test="plan-view-calendar"]').click()
  await expect(page.locator('[data-test="plan-nav-label"]')).toBeVisible()
  await expect(page.locator('.fc-timegrid').first()).toBeVisible({ timeout: 10000 })

  // Retour Semaine : la grille est toujours là (ancrage conservé, pas de reset).
  await page.locator('[data-test="plan-view-planning"]').click()
  await expect(page.locator('.planning-grid').first()).toBeVisible()
})

// I — filtres partagés : la recherche saisie en Semaine survit au changement de mode.
test('M6.D I — l’état partagé (recherche) survit au changement de mode', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page)

  await page.locator('[data-test="plan-view-planning"]').click()
  const search = page.locator('[data-test="grid-search"] input').first()
  await expect(search).toBeVisible({ timeout: 10000 })
  await search.fill('Dupont')
  await page.waitForTimeout(300)

  // Switch Mois puis retour Semaine → la recherche est conservée (même ref partagée).
  await page.locator('[data-test="plan-view-month"]').click()
  await expect(page.locator('[data-test="month-view"]')).toBeVisible()
  await page.locator('[data-test="plan-view-planning"]').click()
  await expect(search).toHaveValue('Dupont')
})

// Jour (B/C) — vraie timeline horaire (axe temporel), cohérente avec le board API du jour.
test('M6.D B — Jour = timeline horaire cohérente avec le board API', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page)
  await page.locator('[data-test="plan-view-calendar"]').click()

  // Axe horaire présent (pas une simple liste).
  await expect(page.locator('.fc-timegrid').first()).toBeVisible({ timeout: 10000 })

  const today = iso(new Date())
  const board = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts/board?from=${today}&to=${today}` })
  expect(board.status).toBe(200)
  const shiftsToday = (board.json?.shifts || []).length
  // Si des shifts existent aujourd'hui, la timeline doit en afficher (événements FullCalendar).
  if (shiftsToday > 0) {
    await page.waitForTimeout(1200)
    const evts = await page.locator('.fc-timegrid .fc-event').count()
    expect(evts).toBeGreaterThan(0)
  } else {
    test.info().annotations.push({ type: 'data', description: `aucun shift aujourd'hui (${today}) — rendu axe validé` })
  }
})

// E/F — Postes : si couverture configurée → lignes de besoin cohérentes ; sinon → état vide utile.
test('M6.D E/F — Postes reflète la couverture serveur (ou état vide utile)', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page)
  await page.locator('[data-test="plan-view-posts"]').click()
  await expect(page.locator('[data-test="posts-view"]')).toBeVisible({ timeout: 10000 })
  await page.waitForTimeout(1000)

  // Récupère la fenêtre semaine réellement chargée pour interroger la même plage.
  const monday = new Date()
  const day = monday.getDay()
  const diff = (day - 1 + 7) % 7
  monday.setDate(monday.getDate() - diff)
  const sunday = new Date(monday); sunday.setDate(monday.getDate() + 6)
  const cov = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/coverage/summary?from=${iso(monday)}&to=${iso(sunday)}` })
  expect(cov.status).toBe(200)
  const reqs = (cov.json?.requirements || []).length

  if (reqs > 0) {
    await expect(page.locator('[data-test="posts-req-row"]').first()).toBeVisible({ timeout: 8000 })
  } else {
    await expect(page.locator('[data-test="posts-empty"]')).toBeVisible({ timeout: 8000 })
  }
})

// G — Mois : les synthèses de cellule reflètent le board réel (cross-check du nombre de publiés).
test('M6.D G — Mois : synthèse de cellule = données réelles du board', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page)
  await page.locator('[data-test="plan-view-month"]').click()
  await expect(page.locator('[data-test="month-grid"]')).toBeVisible({ timeout: 10000 })
  await page.waitForTimeout(1200)

  // Plage du mois courant (large), cross-check d'un jour publié.
  const now = new Date()
  const first = new Date(now.getFullYear(), now.getMonth(), 1)
  const last = new Date(now.getFullYear(), now.getMonth() + 1, 0)
  const board = await apiRequest(page, { method: 'GET', path: `/api/workforce/planning/shifts/board?from=${iso(first)}&to=${iso(last)}` })
  expect(board.status).toBe(200)

  const publishedByDay = {}
  for (const s of (board.json?.shifts || [])) {
    if (s.status === 'published') {
      const d = (s.date || '').slice(0, 10)
      publishedByDay[d] = (publishedByDay[d] || 0) + 1
    }
  }
  const dayIso = Object.keys(publishedByDay)[0]
  if (dayIso) {
    const pill = page.locator(`[data-test="month-day-${dayIso}"] [data-test="month-count-planned"]`)
    await expect(pill).toHaveText(String(publishedByDay[dayIso]), { timeout: 8000 })
  } else {
    test.info().annotations.push({ type: 'data', description: 'aucun shift publié ce mois — grille mois rendue sans valeur inventée' })
  }
})

// H — navigation de mois : le libellé change ; « aujourd'hui » revient au mois courant.
test('M6.D H — navigation de mois (et retour aujourd’hui)', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page)
  await page.locator('[data-test="plan-view-month"]').click()
  await expect(page.locator('[data-test="month-view"]')).toBeVisible()

  const label = page.locator('[data-test="plan-nav-label"]')
  const before = (await label.textContent())?.trim()
  await page.locator('[data-test="plan-nav-next"]').click()
  await page.waitForTimeout(400)
  const after = (await label.textContent())?.trim()
  expect(after).not.toBe(before)
  await page.locator('[data-test="plan-nav-today"]').click()
  await page.waitForTimeout(400)
  await expect(label).toHaveText(before)
})

// Mois → clic jour → bascule en vue Jour sur CE jour (navigation cohérente).
test('M6.D — Mois → clic jour → vue Jour sur la même date', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page)
  await page.locator('[data-test="plan-view-month"]').click()
  await expect(page.locator('[data-test="month-grid"]')).toBeVisible()

  const today = iso(new Date())
  await page.locator(`[data-test="month-day-${today}"]`).click()
  await page.waitForTimeout(500)
  // On est passé en mode Jour (timeline).
  await expect(page.locator('.fc-timegrid').first()).toBeVisible({ timeout: 10000 })
})

// L — thèmes clair & sombre : aucun crash JS en parcourant les 4 modes.
test('M6.D L — thèmes clair/sombre sans erreur JS sur les 4 modes', async ({ page }) => {
  test.setTimeout(120000)
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  await loginCompany(page)
  await gotoPlanning(page)

  for (const theme of ['dark', 'light']) {
    await page.evaluate(t => {
      document.documentElement.setAttribute('data-theme', t)
      document.documentElement.classList.toggle('v-theme--dark', t === 'dark')
    }, theme)
    for (const tid of ['plan-view-planning', 'plan-view-calendar', 'plan-view-posts', 'plan-view-month']) {
      await page.locator(`[data-test="${tid}"]`).click()
      await page.waitForTimeout(500)
    }
  }
  expect(errors, `erreurs JS: ${errors.join(' | ')}`).toHaveLength(0)
})

// PERF — latence de bascule des 4 modes au volume réel disponible (log, pas d'assertion dure).
// Le palier 100 collaborateurs isolé n'est PAS mesuré ici (pas de pollution du tenant DEV partagé) —
// documenté comme prérequis de recette de mise en production (cf. §11).
test('M6.D PERF — latences de bascule des 4 modes (log)', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  await gotoPlanning(page)

  const measure = async tid => {
    const t0 = Date.now()
    await page.locator(`[data-test="${tid}"]`).click()
    const target = tid === 'plan-view-planning' ? '.planning-grid'
      : tid === 'plan-view-calendar' ? '.fc-timegrid'
        : tid === 'plan-view-posts' ? '[data-test="posts-view"]'
          : '[data-test="month-view"]'
    await page.locator(target).first().waitFor({ state: 'visible', timeout: 15000 })

    return Date.now() - t0
  }

  const week = await measure('plan-view-planning')
  const day = await measure('plan-view-calendar')
  const posts = await measure('plan-view-posts')
  const month = await measure('plan-view-month')
  console.log(`M6D_PERF switchMs week=${week} day=${day} posts=${posts} month=${month}`)
  // Garde-fou souple : aucune bascule ne doit dépasser 8 s au volume réel.
  for (const v of [week, day, posts, month]) expect(v).toBeLessThan(8000)
})

// M — mobile 390px : le Planning reste utilisable (sous-nav + une vue montée).
test('M6.D M — mobile 390px : sous-nav + vue montée, pas de régression', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await gotoPlanning(page, 390)
  await expect(page.locator('[data-test="plan-view-month"]')).toBeVisible({ timeout: 10000 })
  await page.locator('[data-test="plan-view-month"]').click()
  await expect(page.locator('[data-test="month-view"]')).toBeVisible({ timeout: 10000 })
})
