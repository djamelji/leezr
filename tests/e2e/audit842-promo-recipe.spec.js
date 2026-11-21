// @ts-check
// Leezr 4.2 ADR-851 — promo code at the public registration, browser recipe on the MARKED clone (fictitious codes
// M851VALIDE / M851EXPIRE from scripts/audit842-promo-fixture.php). The full registration screen is driven by hand :
// a valid code shows the final price before confirmation and is attached to the new subscription ; an unknown and an
// expired code are refused with their reason, and nothing is created for them.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { CLONE_ENV, prepare } from './recipe-fixtures'
import { execFileSync } from 'node:child_process'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 promo recipe only (AUDIT_PASSWORD_FILE)')
test.use({ locale: 'fr-FR' }) // the registration follows the browser language

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/release'
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/promo-${name}.jpg`, type: 'jpeg', quality: 60, fullPage: true }) }

/** read-only check on the clone : the current subscription of the company created with this e-mail */
const subscriptionOf = email => JSON.parse(execFileSync('php', ['-r', `
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$u = DB::table('users')->where('email', '${email}')->value('id');
$c = $u ? DB::table('memberships')->where('user_id', $u)->value('company_id') : null;
echo json_encode(['user' => $u, 'company' => $c, 'sub' => $c ? DB::table('subscriptions')->where('company_id', $c)->where('is_current', 1)->first(['plan_key', 'status', 'coupon_id', 'coupon_months_remaining']) : null,
  'coupon' => DB::table('billing_coupons')->where('code', 'M851VALIDE')->value('id')]);`], { env: CLONE_ENV }).toString())

test.beforeAll(() => {
  fs.mkdirSync(OUT, { recursive: true })
  prepare('promo')
})

async function fillUpToSummary(page, email) {
  await page.goto('/register', { waitUntil: 'networkidle' })
  await page.getByText('Logistique', { exact: false }).first().click()
  await page.getByRole('button', { name: 'Suivant' }).click()
  await page.getByText(/^Pro$/).first().click()
  await page.getByRole('button', { name: 'Suivant' }).click()
  await page.getByRole('button', { name: 'Suivant' }).click() // options
  await page.getByLabel('Nom de l\'entreprise').fill(`Promo ${email}`)

  const siret = page.getByLabel(/SIRET/i)
  if (await siret.count()) await siret.first().fill('73282932000074')
  await page.getByRole('button', { name: 'Suivant' }).click()
  await page.getByLabel('Prénom').fill('Promo')
  await page.getByLabel('Nom', { exact: true }).fill('Recette')
  await page.getByLabel(/e-mail/i).first().fill(email)
  await page.getByLabel(/^Mot de passe/).first().fill(`Rc${PASSWORD}#9a`)
  await page.getByRole('button', { name: 'Suivant' }).click()
  await expect(page.getByLabel('Code promo')).toBeVisible()
}

test('Code valide : tarif final affiché avant confirmation, réduction rattachée au nouvel abonnement', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  page.setDefaultTimeout(20000)

  const email = `promo.valide.${Date.now()}@audit842.test`

  await fillUpToSummary(page, email)
  await page.getByLabel('Code promo').fill('m851valide') // case-insensitive
  await page.getByRole('button', { name: 'Appliquer' }).click()
  await expect(page.locator('body')).toContainText('Recette ADR-851 (valide)')
  await expect(page.locator('body')).toContainText(/-\s?5,80\s?€|−\s?5,80\s?€/) // 20 % of 29 € shown before confirmation
  await shot(page, 'A1-code-valide-tarif-final')
  await page.getByRole('button', { name: /Créer le compte|essai|Passer au paiement/i }).last().click() // Pro has a trial : « start the free trial »
  await expect.poll(() => subscriptionOf(email).sub?.coupon_id ?? null, { timeout: 30000 }).not.toBeNull()

  const state = subscriptionOf(email)

  expect(state.sub['coupon_id']).toBe(state.coupon)
  expect(state.sub['coupon_months_remaining']).toBe(3)
  await shot(page, 'A2-apres-inscription')
})

test('Codes invalide et expiré : refusés avec leur motif, rien n’est créé', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  page.setDefaultTimeout(20000)

  const email = `promo.refus.${Date.now()}@audit842.test`

  await fillUpToSummary(page, email)
  await page.getByLabel('Code promo').fill('INCONNU851')
  await page.getByRole('button', { name: 'Appliquer' }).click()
  await expect(page.locator('body')).toContainText('Ce code promo n\'existe pas.')
  await page.getByLabel('Code promo').fill('M851EXPIRE')
  await page.getByRole('button', { name: 'Appliquer' }).click()
  await expect(page.locator('body')).toContainText('Ce code promo a expiré.')
  await shot(page, 'B1-codes-refuses')

  // the server refuses it too, even if the screen were bypassed — and nothing is created
  const refused = await page.evaluate(async e => {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' })

    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')

    const r = await fetch('/api/register', {
      method: 'POST', credentials: 'include',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Locale': 'fr' },
      body: JSON.stringify({ 'first_name': 'P', 'last_name': 'R', 'email': e, 'password': 'Rc-Strong#2026-x', 'password_confirmation': 'Rc-Strong#2026-x', 'company_name': `Promo ${e}`,
        'jobdomain_key': 'logistique', 'plan_key': 'pro', 'billing_interval': 'monthly', 'dynamic_fields': { siret: '73282932000074' }, 'coupon_code': 'M851EXPIRE' }),
    })

    return { status: r.status, reason: (await r.json()).errors?.['coupon_code']?.[0] }
  }, email)

  expect(refused).toEqual({ status: 422, reason: 'Ce code promo a expiré.' })
  expect(subscriptionOf(email).user).toBeNull()
})
