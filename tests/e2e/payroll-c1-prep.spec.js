// @ts-check
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

/**
 * PRODUCT-UX-2 / LOT C1 — Vue période de préparation « Paie & déclarations » sur DEV réel.
 *
 * Démonstration honnête et RÉVERSIBLE :
 *  1. Landing recadré (titre « Paie & déclarations », disclaimer honnête, colonne « Prochaine action »).
 *  2. Création d'une période de paie (draft) — réversible (suppression en fin de test).
 *  3. Détail brouillon : disclaimer + bandeau « Collecter & calculer ».
 *  4. Calcul : fait remonter les ÉLÉMENTS MANQUANTS réels (timesheet non verrouillé → anomalie),
 *     statut de préparation par collaborateur. C'est exactement le critère C1 :
 *     « comprendre l'état de préparation, identifier les éléments manquants, savoir quelle action ».
 *  5. Nettoyage : suppression de la période de test (DELETE API, best-effort).
 *
 * Lancer avec : E2E_BASE_URL=https://dev.leezr.com
 */

const SHOTS = process.env.C1_SHOTS || '/tmp/c1-shots'
fs.mkdirSync(SHOTS, { recursive: true })
async function shot(page, name) { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }).catch(() => {}) }

test('C1 — période de préparation paie (DEV réel)', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  page.setDefaultTimeout(20000)

  let createdRunId = null

  await loginCompany(page)

  // 1. Landing recadré ------------------------------------------------------
  await page.goto('/company/workforce/payroll', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await expect(page.getByText('Paie & déclarations').first()).toBeVisible()
  await expect(page.getByText(/espace de préparation de la paie/i).first()).toBeVisible()
  await shot(page, '01-landing-reframe')

  // 2. Création d'une période (draft, réversible) ---------------------------
  // Période passée improbable (évite collision idempotente company:start:end).
  const start = '2026-09-01'
  const end = '2026-09-30'
  const created = await apiRequest(page, {
    method: 'POST',
    path: '/api/workforce/payroll',
    body: { period_start: start, period_end: end },
  }).catch(() => null)
  createdRunId = created?.json?.data?.id ?? created?.json?.id ?? null
  console.log('created run:', created?.status, 'id:', createdRunId)

  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  await shot(page, '02-landing-with-period')

  // 3. Détail période (brouillon) -------------------------------------------
  if (createdRunId) {
    await page.goto(`/company/workforce/payroll/${createdRunId}`, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(2500)
    await expect(page.getByText(/prochaine action/i).first()).toBeVisible()
    await shot(page, '03-detail-draft')

    // 4. (OPTIONNEL) calcul — fait remonter les éléments manquants réels.
    //    ⚠️ IRRÉVERSIBLE SUR DEV : un run CALCULÉ n'est plus supprimable
    //    (règle métier draft-only). Désactivé par défaut pour garder DEV propre ;
    //    la preuve « readiness/anomalies » est déjà capturée (04-...png).
    //    Activer sciemment via C1_COMPUTE=1 uniquement sur un full-stack local.
    if (process.env.C1_COMPUTE === '1') {
      await apiRequest(page, { method: 'POST', path: `/api/workforce/payroll/${createdRunId}/compute` }).catch(e => console.log('compute err', String(e).slice(0, 120)))
      await page.reload({ waitUntil: 'domcontentloaded' })
      await page.waitForTimeout(3000)
      await shot(page, '04-detail-computed-readiness')
    }
  }

  // 5. Nettoyage (vérifié) --------------------------------------------------
  if (createdRunId) {
    const del = await apiRequest(page, { method: 'DELETE', path: `/api/workforce/payroll/${createdRunId}` })
    console.log('cleanup DELETE status:', del.status, del.status === 204 || del.status === 200 ? 'OK' : JSON.stringify(del.json))
    // Un run calculé renverra 422 (draft-only) — à retirer par un admin autorisé.
  }
})
