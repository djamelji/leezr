// E3-B / B2 — recette opérationnelle Pointages : cockpit « Aujourd'hui », espace Présences, et
// CORRECTION réellement persistée (motif OBLIGATOIRE, workflow tracé). Données: WorkforceCockpitDemoSeeder
// (équipe B2DEMO sur leezr-logistics).
// ADR-831 (D-21 bis) — la spec dépendait du seeder de démonstration lancé à la main sur le DEV. Elle prépare
// désormais SON pointage du jour par les commandes de recette gardées (clone marqué uniquement) : journée de
// Camille Laurent remise à zéro, pointage ouvert il y a 25 min, puis journée nettoyée en fin de test.
import { execFileSync } from 'node:child_process'
import { test, expect } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

const recipe = (...args) => execFileSync('php', ['artisan', ...args], { cwd: process.cwd(), stdio: 'pipe' })
let camille = null

test.afterAll(() => { if (camille) recipe('e2e:reset-clock-day', `--employee=${camille}`, '--clear-open-days') })

test('cockpit pointages : synthèse + correction tracée persistée', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)
  // Camille Laurent (jeu de données cloné), résolue par l'API — aucun identifiant codé en dur.
  const company = (await apiRequest(page, { path: '/api/profile/space' })).json.company_id
  const found = await apiRequest(page, { path: '/api/workforce/employees?search=Laurent&per_page=50' })
  camille = (found.json?.data || []).find(e => e.first_name === 'Camille' && e.last_name === 'Laurent')?.id
  expect(camille, 'Camille Laurent existe dans le jeu de données').toBeTruthy()
  recipe('e2e:reset-clock-day', `--employee=${camille}`, '--clear-open-days')
  recipe('e2e:prepare-clock', `--employee=${camille}`, `--company=${company}`, '--minutes-ago=25')

  // ── Cockpit « Aujourd'hui » : synthèse opérationnelle (KPI) + board prévu/réel ──
  await page.goto('/company/workforce/time', { waitUntil: 'networkidle' })
  await expect(page.getByText('Camille Laurent').first()).toBeVisible({ timeout: 20000 })

  // ── Présences : correction d'un pointage via le workflow tracé ──
  await page.goto('/company/workforce/time?tab=entries', { waitUntil: 'networkidle' })
  // ADR-831 — the prepared entry (Camille, opened 25 min ago), not whichever row comes first.
  const correctBtn = page.locator('tr', { hasText: 'Camille Laurent' }).locator('[data-test="entry-correct"]').first()
  await correctBtn.waitFor({ timeout: 20000 })
  await correctBtn.click()

  const reason = page.locator('[data-test="entry-correct-reason"] textarea').first()
  await reason.waitFor({ timeout: 10000 })

  // Motif OBLIGATOIRE : tant qu'il est vide, l'envoi est désactivé (pas de correction silencieuse).
  await expect(page.locator('[data-test="entry-correct-submit"]')).toBeDisabled()

  // Sortie cohérente avec le pointage préparé (ouvert il y a 25 min) : il y a 2 min, heure locale du navigateur.
  const clockOut = await page.evaluate(() => { const d = new Date(Date.now() - 120000); const p = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}` })
  await page.locator('[data-test="entry-correct-out"] input').first().fill(clockOut)
  await reason.fill('Oubli de pointage de sortie — corrigé par le responsable (recette B2).')
  await expect(page.locator('[data-test="entry-correct-submit"]')).toBeEnabled()

  // La correction est RÉELLEMENT persistée (PUT /time-entries/{id} → 200), pas simulée.
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/api\/workforce\/time-entries\/\d+$/.test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 }),
    page.locator('[data-test="entry-correct-submit"]').click(),
  ])
  expect(resp.status()).toBe(200)
})
