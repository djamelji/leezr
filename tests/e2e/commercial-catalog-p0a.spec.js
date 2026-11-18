// ADR-732 (P0-A) — Sécurisation du catalogue commercial, depuis la VRAIE interface.
// Prouve : (A) les modules « En préparation » (logistics_fleet/tracking/analytics) ne sont PAS proposés
// à l'achat dans le catalogue Company (pas de prix, CTA désactivé « En préparation ») ; (B) l'endpoint
// d'activation REFUSE l'achat (422 not_commercialisable) même si le frontend est contourné.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

const PREVIEW = ['logistics_fleet', 'logistics_tracking', 'logistics_analytics']

test('P0-A A — catalogue Company : modules fictifs « En préparation », non achetables', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.goto('/company/modules', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)

  // Le backend expose commercial_status + purchasable ; aucun prix commandable pour les 3.
  const catalog = await apiRequest(page, { method: 'GET', path: '/api/modules' })
  const rows = (catalog.json?.modules ?? catalog.json?.data ?? catalog.json ?? [])
  const byKey = Object.fromEntries(rows.map(m => [m.key, m]))
  // ADR-818 (Logistics Only) supersedes the « shown as En préparation » rule : an undelivered module is
  // ABSENT from the catalogue of a company that does not have it ; a company that already has it keeps
  // seeing it, still non purchasable (ADR-829/830 D-21 realignment — the protection asserted is unchanged).
  const shown = PREVIEW.filter(k => byKey[k])
  for (const k of PREVIEW.filter(k => !byKey[k])) expect(byKey[k], `${k} absent (non livré, non détenu)`).toBeUndefined()
  for (const k of shown) {
    // visible only because the company already holds it (activation or historical add-on subscription — ADR-818) ;
    // whichever the reason, it must never be purchasable :
    expect(byKey[k].commercial_status, `${k} en préparation`).toBe('preview')
    expect(byKey[k].purchasable, `${k} non achetable`).toBe(false)
    expect(byKey[k].addon_pricing, `${k} aucun prix commandable`).toBeNull()
    expect(byKey[k].purchase_mode, `${k} aucun mode d'achat`).toBeNull()
  }

  // UI : au moins un CTA « En préparation » désactivé, et aucune action d'achat pour ces modules.
  await expect(page.locator(PREVIEW.map(k => `[data-test="module-activate-${k}"]`).join(', ')), 'aucune action d\'achat').toHaveCount(0)
  const previewCtas = page.locator('[data-test="module-preview-cta"]')
  if (await previewCtas.count()) await expect(previewCtas.first()).toBeDisabled()
})

test('P0-A B — contournement frontend : l’endpoint refuse l’achat (422 not_commercialisable)', async ({ page }) => {
  test.setTimeout(90000)
  await loginCompany(page)
  await page.goto('/company/modules', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(600)

  for (const k of PREVIEW) {
    const res = await apiRequest(page, { method: 'PUT', path: `/api/modules/${k}/enable` })
    expect(res.status, `${k} : activation refusée`).toBe(422)
    expect(res.json?.reason ?? res.json?.data?.reason).toBe('not_commercialisable')
  }

  // Non-régression : un module réel reste activable côté endpoint (pas de 422 commercial).
  const real = await apiRequest(page, { method: 'PUT', path: '/api/modules/workforce_planning/enable' })
  expect([200, 201, 409, 422].includes(real.status)).toBe(true)
  if (real.status === 422) expect(real.json?.reason ?? real.json?.data?.reason).not.toBe('not_commercialisable')
})
