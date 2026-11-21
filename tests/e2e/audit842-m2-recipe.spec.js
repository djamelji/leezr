// @ts-check
// Leezr 4.2 M2 (ADR-838) — browser recipe with REAL clicks on the marked clone (global-setup, ADR-831).
// Fixture : scripts/audit842-m2-fixture.php (fictitious member842 in company 106, recipe accounts without 2FA).
// Secrets (TOTP secret, backup codes, passwords) are never printed ; every capture masks them.
import crypto from 'node:crypto'
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers.js'
import { fixtureJson, prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M2 recipe only (AUDIT_PASSWORD_FILE)')

const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m2'
const OWNER = 'owner.at4f8@audit842.test'

// ADR-850 (M10) : M2 changes a password and enables 2FA → its OWN accounts (recreated by audit842-m2-fixture at every run).
// The owner only reads the audit log and sets the dedicated member's password ; member842 / viewer842 are never touched.
const MEMBER = 'pwd842@audit842.test'
const TWOFA = 'twofa842@audit842.test'
const TWOFA_OPERATOR = 'twofa842@platform.test'
let IDS = {}
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025'

// RFC 6238 TOTP (SHA-1, 30 s, 6 digits) — same parameters as the server (Google2FA)
const totp = (secret, offset = 0) => {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)))
  const counter = Buffer.alloc(8)

  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30) + offset))

  const h = crypto.createHmac('sha1', key).update(counter).digest()
  const o = h[h.length - 1] & 0xF

  return String(((h.readUInt32BE(o) & 0x7FFFFFFF) % 1000000)).padStart(6, '0')
}

const masks = page => [page.getByTestId('two-factor-backup-code'), page.locator('.v-overlay--active code'), page.locator('.v-overlay--active canvas, .v-overlay--active svg, .v-overlay--active img')]
const shot = async (page, name) => (await page.waitForTimeout(450), page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60, mask: masks(page) }))

const mails = async to => {
  const r = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${to}`)}&limit=50`)

  return r.ok ? (await r.json()).messages || [] : []
}

// ADR-848 (M9) : Mailpit search pages at 50 — count with the total it reports, not the length of one page
const mailCount = async to => {
  const r = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${to}`)}&limit=1`)

  return r.ok ? (await r.json()).messages_count ?? 0 : 0
}


// ADR-850 (M10) : the recipe prepares itself (its own accounts, reset at every run)
test.beforeAll(() => { IDS = fixtureJson(prepare('m2').m2) })
test.beforeEach(({ page }) => { page.setDefaultTimeout(15000); fs.mkdirSync(OUT, { recursive: true }) })

/** Enable 2FA through the UI, return { secret, codes } (kept in memory only). */
async function enableThroughUi(page) {
  await page.getByRole('button', { name: /Activer la 2FA/ }).click()

  const dialog = page.locator('.v-overlay--active .v-card', { hasText: 'Configurer' })

  await expect(dialog).toBeVisible()

  const secret = (await dialog.locator('code').innerText()).trim()

  await dialog.getByLabel('Code de vérification').fill(totp(secret))
  await dialog.getByRole('button', { name: 'Vérifier' }).click()

  // ADR-838 : the initial backup codes are shown once, right after activation
  await expect(page.getByTestId('two-factor-backup-code').first()).toBeVisible()

  const codes = await page.getByTestId('two-factor-backup-code').allInnerTexts()

  return { secret, codes: codes.map(c => c.trim()) }
}

test('M2-A — Company : régénération et désactivation exigent un facteur valide', async ({ page, browser }) => {
  test.setTimeout(4 * 60 * 1000)

  const before = (await mailCount(TWOFA))

  await loginCompany(page, TWOFA, PASSWORD)
  await page.goto('/account-settings/security', { waitUntil: 'networkidle' })

  const { secret, codes } = await enableThroughUi(page)

  expect(codes).toHaveLength(10)
  await shot(page, 'A1-codes-initiaux-masques')
  await page.getByTestId('two-factor-codes-close').click()
  await expect(page.getByTestId('two-factor-backup-code')).toHaveCount(0)

  // regenerate : wrong code refused, old codes untouched
  await page.getByTestId('two-factor-regenerate').click()
  await page.getByTestId('two-factor-regenerate-code').locator('input').fill('ZZZZ-ZZZZ')
  await page.getByTestId('two-factor-regenerate-confirm').click()
  await expect(page.getByTestId('two-factor-regenerate-error')).toContainText('Code invalide ou expiré')
  await shot(page, 'A2-regeneration-code-invalide')

  // regenerate with a backup code → new codes, shown once
  await page.getByTestId('two-factor-regenerate-code').locator('input').fill(codes[0])
  await page.getByTestId('two-factor-regenerate-confirm').click()
  await expect(page.getByTestId('two-factor-backup-code').first()).toBeVisible()

  const fresh = (await page.getByTestId('two-factor-backup-code').allInnerTexts()).map(c => c.trim())

  expect(fresh).toHaveLength(10)
  expect(fresh.filter(c => codes.includes(c))).toEqual([])
  await shot(page, 'A3-nouveaux-codes-masques')
  await page.getByTestId('two-factor-codes-close').click()

  // an OLD code no longer works
  await page.getByTestId('two-factor-regenerate').click()
  await page.getByTestId('two-factor-regenerate-code').locator('input').fill(codes[1])
  await page.getByTestId('two-factor-regenerate-confirm').click()
  await expect(page.getByTestId('two-factor-regenerate-error')).toBeVisible()
  await page.keyboard.press('Escape')

  // disable : password alone is not enough (button stays disabled), a wrong code is refused, a current TOTP works
  await page.getByTestId('two-factor-disable').click()
  await page.getByTestId('two-factor-disable-password').locator('input').fill(PASSWORD)
  await expect(page.getByTestId('two-factor-disable-confirm')).toBeDisabled()
  await expect(page.getByTestId('two-factor-disable-code')).toBeVisible()
  await shot(page, 'A4-desactivation-mot-de-passe-seul')
  await page.getByTestId('two-factor-disable-code').locator('input').fill('000000')
  await page.getByTestId('two-factor-disable-confirm').click()
  await expect(page.getByTestId('two-factor-disable-error')).toContainText('Code invalide ou expiré')
  await page.getByTestId('two-factor-disable-code').locator('input').fill(totp(secret))
  await page.getByTestId('two-factor-disable-confirm').click()
  await expect(page.getByRole('button', { name: /Activer la 2FA/ })).toBeVisible()
  await shot(page, 'A5-desactivee')

  // security notices reached the (controlled) Mailpit inbox ; none contains a code
  await expect.poll(async () => (await mailCount(TWOFA)), { timeout: 15000 }).toBeGreaterThanOrEqual(before + 2)

  const latest = (await mails(TWOFA)).slice(0, 2)
  for (const m of latest) {
    expect(m.Subject).toBe('Sécurité de votre compte Leezr')

    const body = await (await fetch(`${MAILPIT}/api/v1/message/${m.ID}`)).json()
    for (const c of [...codes, ...fresh, secret]) expect(body.Text).not.toContain(c)
  }

  // audit : success and refusals, never a secret — read by the company owner (the 2FA account is a plain member)
  const ownerCtx = await browser.newContext()
  const ownerPage = await ownerCtx.newPage()

  await loginCompany(ownerPage, OWNER, PASSWORD)

  const audit = await ownerPage.evaluate(async () => (await fetch('/api/audit?per_page=50', { headers: { Accept: 'application/json', 'X-Company-Id': '106' }, credentials: 'include' })).text())

  await ownerCtx.close()
  for (const action of ['two_factor.enabled', 'two_factor.backup_codes_regenerated', 'two_factor.backup_codes_refused', 'two_factor.disable_refused', 'two_factor.disabled'])
    expect(audit, action).toContain(action)
  for (const c of [...codes, ...fresh, secret, PASSWORD]) expect(audit).not.toContain(c)
})

test('M2-B — mot de passe d’un membre : notice, robustesse, sessions fermées, journal', async ({ page, browser }) => {
  test.setTimeout(4 * 60 * 1000)


  // the member is signed in elsewhere
  const memberCtx = await browser.newContext()
  const memberPage = await memberCtx.newPage()

  await loginCompany(memberPage, MEMBER, PASSWORD)

  const before = (await mailCount(MEMBER))

  await loginCompany(page, OWNER, PASSWORD)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await page.goto(`/company/people/user-${IDS.pwd_user_id}/access`, { waitUntil: 'networkidle' })
  await page.locator('[data-test="person-set-password"]').click()
  await expect(page.locator('[data-test="person-set-password-notice"]')).toContainText('lien de réinitialisation est recommandé')

  const strong = `Rc${crypto.randomBytes(9).toString('base64url')}#7`
  const fields = page.locator('input[autocomplete="new-password"]')

  await fields.nth(0).fill('faible')
  await fields.nth(1).fill('faible')
  await page.getByRole('button', { name: /Enregistrer le mot de passe|Save password/ }).click()
  await page.waitForTimeout(1200)
  await shot(page, 'B1-mot-de-passe-faible-refuse')
  await fields.nth(0).fill(strong)
  await fields.nth(1).fill(strong)
  await page.getByRole('button', { name: /Enregistrer le mot de passe|Save password/ }).click()
  await expect(page.locator('[data-test="person-set-password-notice"]')).toHaveCount(0, { timeout: 15000 })
  await shot(page, 'B2-mot-de-passe-defini')

  // the member's session is over
  const status = await memberPage.evaluate(async () => (await fetch('/api/profile', { headers: { Accept: 'application/json', 'X-Company-Id': '106' }, credentials: 'include' })).status)

  expect(status, 'the member was signed out').toBe(401)
  await memberPage.reload({ waitUntil: 'networkidle' })
  await expect(memberPage).toHaveURL(/login/)
  await memberPage.screenshot({ path: `${OUT}/apres-B3-membre-deconnecte.jpg`, type: 'jpeg', quality: 60 })
  await memberCtx.close()

  await expect.poll(async () => (await mailCount(MEMBER)), { timeout: 15000 }).toBeGreaterThan(before)

  const audit = await page.evaluate(async () => (await fetch('/api/audit?per_page=50', { headers: { Accept: 'application/json', 'X-Company-Id': '106' }, credentials: 'include' })).text())

  expect(audit).toContain('member.password_set')
  expect(audit).not.toContain(strong)
})

test('M2-C — Platform : mêmes règles sur le compte d’un opérateur', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginPlatform(page, TWOFA_OPERATOR, PASSWORD)
  await page.goto('/platform/account/security', { waitUntil: 'networkidle' })

  const { secret, codes } = await enableThroughUi(page)

  await page.getByTestId('two-factor-codes-close').click()

  await page.getByTestId('two-factor-disable').click()
  await page.getByTestId('two-factor-disable-password').locator('input').fill(PASSWORD)
  await expect(page.getByTestId('two-factor-disable-confirm')).toBeDisabled()
  await page.getByTestId('two-factor-disable-code').locator('input').fill('ZZZZ-ZZZZ')
  await page.getByTestId('two-factor-disable-confirm').click()
  await expect(page.getByTestId('two-factor-disable-error')).toBeVisible()
  await shot(page, 'C1-platform-desactivation-refusee')
  await page.getByTestId('two-factor-disable-code').locator('input').fill(codes[3])
  await page.getByTestId('two-factor-disable-confirm').click()
  await expect(page.getByRole('button', { name: /Activer la 2FA/ })).toBeVisible()
  expect(secret.length).toBeGreaterThan(10)

  // a Platform session never reaches the Company 2FA routes
  const status = await page.evaluate(async () => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')

    return (await fetch('/api/2fa/backup-codes', { method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }, body: '{"code":"123456"}' })).status
  })

  expect(status).toBe(401)
})
