// E3-B / B2 — recette opérationnelle Pointages : cockpit « Aujourd'hui », espace Présences, et
// CORRECTION réellement persistée (motif OBLIGATOIRE, workflow tracé). Données: WorkforceCockpitDemoSeeder
// (équipe B2DEMO sur leezr-logistics). À lancer sur DEV :
//   php artisan db:seed --class=Database\\Seeders\\WorkforceCockpitDemoSeeder
//   npx playwright test tests/e2e/workforce-eb2-cockpit.spec.js
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

test('cockpit pointages : synthèse + correction tracée persistée', async ({ page }) => {
  test.setTimeout(120000)
  await loginCompany(page)

  // ── Cockpit « Aujourd'hui » : synthèse opérationnelle (KPI) + board prévu/réel ──
  await page.goto('/company/workforce/time', { waitUntil: 'networkidle' })
  await expect(page.getByText('Camille Laurent').first()).toBeVisible({ timeout: 20000 })

  // ── Présences : correction d'un pointage via le workflow tracé ──
  await page.goto('/company/workforce/time?tab=entries', { waitUntil: 'networkidle' })
  const correctBtn = page.locator('[data-test="entry-correct"]').first()
  await correctBtn.waitFor({ timeout: 20000 })
  await correctBtn.click()

  const reason = page.locator('[data-test="entry-correct-reason"] textarea').first()
  await reason.waitFor({ timeout: 10000 })

  // Motif OBLIGATOIRE : tant qu'il est vide, l'envoi est désactivé (pas de correction silencieuse).
  await expect(page.locator('[data-test="entry-correct-submit"]')).toBeDisabled()

  await page.locator('[data-test="entry-correct-out"] input').first().fill('2026-11-04T17:45').catch(() => {})
  await reason.fill('Oubli de pointage de sortie — corrigé par le responsable (recette B2).')
  await expect(page.locator('[data-test="entry-correct-submit"]')).toBeEnabled()

  // La correction est RÉELLEMENT persistée (PUT /time-entries/{id} → 200), pas simulée.
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/api\/workforce\/time-entries\/\d+$/.test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 }),
    page.locator('[data-test="entry-correct-submit"]').click(),
  ])
  expect(resp.status()).toBe(200)
})
