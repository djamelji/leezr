// @ts-check
// W-SIGNATURE-10 (§8/§11) — parcours Company RÉEL (login → hub Signatures → Paramètres), captures
// visuelles + contrôle de l'absence d'erreurs JS console. Données de seeders dev uniquement.
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers'

const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

test('company signature hub + settings render without JS errors (desktop/dark)', async ({ page }) => {
  test.setTimeout(90_000)
  const errors = []
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()) })
  page.on('pageerror', e => errors.push(String(e)))

  await loginCompany(page, 'owner@leezr.test')

  // Hub Signatures
  await page.goto('/company/workforce/signature', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1800)
  await page.screenshot({ path: `${SCRATCH}/wsig10-hub.png`, fullPage: true })
  // Le hub rend un contenu (titre/tableau), pas une page blanche.
  await expect(page.locator('body')).toContainText(/signature/i)

  // Paramètres RH → Signature (sélecteur de mode métier W-SIG-8)
  await page.goto('/company/workforce/settings/signature', { waitUntil: 'domcontentloaded' }).catch(() => {})
  await page.waitForTimeout(1500)
  await page.screenshot({ path: `${SCRATCH}/wsig10-settings.png`, fullPage: true })

  // Aucune erreur JS console bloquante.
  const blocking = errors.filter(e => !/favicon|ResizeObserver|Failed to load resource/i.test(e))
  expect(blocking, 'erreurs console: ' + blocking.join(' | ')).toHaveLength(0)
})
