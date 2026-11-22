// @ts-check
// Leezr 4.2 M5 (ADR-842) — Platform ergonomics, browser recipe with REAL navigation on the MARKED clone (ADR-831).
// « Before » captures : docs/bmad/leezr-4-2-audit/captures/ (audit ADR-836). « After » : docs/bmad/leezr-4-2-audit/m5/.
import fs from 'node:fs'
import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'
import { CLONE_ENV, prepare } from './recipe-fixtures'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M5 recipe only (AUDIT_PASSWORD_FILE)')
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m5'
const COMPANY = 106
const shot = async (page, name) => { await page.waitForTimeout(600); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 }) }

function watch(page) {
  const problems = []
  page.on('pageerror', e => problems.push(`js ${e.message}`))
  page.on('console', m => { if (m.type() === 'warning' && /Failed to resolve (directive|component)/.test(m.text())) problems.push(`vue ${m.text()}`) })
  page.on('response', r => {
    if (r.status() >= 400 && r.url().includes('/api/') && !r.url().includes('/realtime/') && !(r.status() === 429 && r.url().includes('/api/public/')))
      problems.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')}`)
  })

  return problems
}
const open = async (page, path) => { await page.goto(path, { waitUntil: 'networkidle' }); await page.waitForTimeout(900) }
const groupTitles = page => page.getByTestId('billing-groups').getByRole('tab').allInnerTexts()


// ADR-850 (M10) : the recipe prepares itself — no order, no state inherited from another recipe
test.beforeAll(() => { prepare('profiles', 'm1') }) // ADR-850 : company 106 in a KNOWN plan (Pro annual)
test.beforeEach(({ page }) => { page.setDefaultTimeout(15000); fs.mkdirSync(OUT, { recursive: true }) })

test('Super-admin — Facturation en 5 groupes, adresses conservées, clavier, mobile', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  const problems = watch(page)
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)

  // from the menu
  await open(page, '/platform/dashboard/overview')
  await page.getByRole('link', { name: 'Facturation' }).first().click()
  await page.waitForURL(/\/platform\/billing\/overview/)
  await page.waitForTimeout(1200)
  expect((await groupTitles(page)).map(s => s.trim())).toEqual(["Vue d'ensemble", 'Abonnements', 'Factures et paiements', 'Offres commerciales', 'Contrôle et suivi'])
  await shot(page, 'A1-facturation-groupes')

  // a group opens its first page ; its pages are listed below ; the group explains itself
  await page.getByTestId('billing-group-invoicing').click()
  await page.waitForURL(/\/platform\/billing\/invoices/)
  await expect(page.getByTestId('billing-group-hint')).toContainText('encaissements')
  await expect(page.getByTestId('billing-pages').getByRole('tab')).toHaveCount(6)
  await page.getByTestId('billing-page-dunning').click()
  await page.waitForURL(/\/platform\/billing\/dunning/)
  await shot(page, 'A2-factures-et-paiements')

  // every historical address still opens the same page, inside the right group
  for (const [tab, group] of [['ledger', 'control'], ['tariff-review', 'subscriptions'], ['coupons', 'offers'], ['wallets', 'invoicing'], ['scheduled-debits', 'invoicing'], ['reconciliation', 'control']]) {
    await open(page, `/platform/billing/${tab}`)
    await expect(page.getByTestId(`billing-group-${group}`)).toHaveAttribute('aria-selected', 'true')
    await expect(page.getByTestId('platform-tab-forbidden')).toHaveCount(0)
    if (tab === 'coupons')
      await expect(page.getByTestId('billing-offers-plans-link')).toBeVisible() // coupons → link to Catalogue › Plans
  }

  // back button returns to the previous page
  await page.goBack()
  await page.waitForURL(/\/platform\/billing\/scheduled-debits/)

  // keyboard : focus a group tab and activate it with Enter
  await page.getByTestId('billing-group-subscriptions').focus()
  await page.keyboard.press('Enter')
  await page.waitForURL(/\/platform\/billing\/subscriptions/)

  // mobile : no horizontal page scroll, groups still reachable
  await page.setViewportSize({ width: 390, height: 844 })
  await open(page, '/platform/billing/invoices')
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow, 'no horizontal page scroll on a phone').toBeLessThanOrEqual(1)
  await shot(page, 'A3-facturation-mobile')
  await page.setViewportSize({ width: 1440, height: 900 })
  expect(problems).toEqual([])
})

test('Super-admin — fiche entreprise sans doublon, changement d’offre avec aperçu dans « Abonnement et facturation »', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const problems = watch(page)
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await open(page, '/platform/companies')
  // ADR-855 — other recipes create « Audit 842 Services … » companies too : search THIS company's exact name
  const companyName = execFileSync('php', ['-r', `require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo DB::table('companies')->where('id', ${COMPANY})->value('name');`], { env: CLONE_ENV }).toString().trim()
  await page.getByPlaceholder(/Rechercher/).first().fill(companyName)
  await page.waitForTimeout(1500)
  await expect(page.locator('body')).not.toContainText(' ago')
  await expect(page.getByRole('button', { name: 'Ouvrir la fiche' }).first()).toBeVisible() // named icon button
  await shot(page, 'B1-liste-entreprises')
  await page.getByRole('button', { name: 'Ouvrir la fiche' }).first().click()
  await page.waitForURL(new RegExp(`/platform/companies/${COMPANY}`))
  await page.waitForTimeout(1200)
  await expect(page.locator('[data-test="pc-summary"]')).toHaveCount(0) // identity only in the left panel
  await expect(page.getByRole('tab', { name: 'Identité et adresses' })).toBeVisible()
  await shot(page, 'B2-fiche-synthese')

  await page.getByRole('tab', { name: 'Abonnement et facturation' }).click()
  await page.waitForTimeout(1200)
  const card = page.getByTestId('company-plan-change')
  await expect(card).toContainText('aperçu chiffré')
  await card.locator('.v-select').getByRole('combobox').first().click()
  // ADR-850 : the company is prepared in Pro → preview a change towards ANOTHER plan
  await page.locator('.v-overlay--active .v-list-item', { hasText: 'Business' }).first().click()
  const dialog = page.locator('.v-overlay--active .v-card', { hasText: 'Aperçu du changement de plan' })
  await expect(dialog).toBeVisible()
  await shot(page, 'B3-apercu-changement-offre')
  await dialog.getByRole('button', { name: /Annuler/ }).click() // preview only : nothing changed
  await expect(dialog).toHaveCount(0)
  await page.getByRole('tab', { name: 'Identité et adresses' }).click()
  await page.waitForTimeout(800)
  await expect(page.getByRole('combobox', { name: /Plan|Abonnement/ })).toHaveCount(0) // no commercial decision in the identity form
  expect(problems.filter(p => !/plan-preview/.test(p))).toEqual([])
})

test('Super-admin — journal des e-mails lisible, fiche module sans onglets vides', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const problems = watch(page)
  await loginPlatform(page, 'admin@leezr.com', PASSWORD)
  await open(page, '/platform/messaging/logs')
  const subjects = await page.getByTestId('email-log-subject').allInnerTexts()
  expect(subjects.filter(s => /^email\./.test(s.trim())), 'no raw translation key as subject').toEqual([])
  await shot(page, 'C1-journal-emails')
  await open(page, '/platform/modules/core.audit')
  const tabs = (await page.locator('[data-test="pm-tabs"]').getByRole('tab').allInnerTexts()).length
  expect(tabs, 'a simple module shows fewer than the 7 sections').toBeLessThan(7)
  await shot(page, 'C2-fiche-module')
  expect(problems).toEqual([])
})

test('Finance — groupes réduits à ses droits, sans personnalisateur de thème', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const problems = watch(page)
  await loginPlatform(page, 'finance-reader@leezr.com', PASSWORD)
  await open(page, '/platform/billing/overview')
  const groups = (await groupTitles(page)).map(s => s.trim())
  expect(groups).not.toContain('Offres commerciales') // coupons need manage_billing
  await page.getByTestId('billing-group-subscriptions').click()
  await expect(page.getByTestId('billing-pages')).toHaveCount(0) // a single page : no secondary row
  await shot(page, 'D-finance-facturation')
  await expect(page.getByRole('button', { name: 'Personnaliser' })).toHaveCount(0)
  expect(await page.locator('.customizer-toggler, [class*="customizer"]').count(), 'theme customizer hidden').toBe(0)
  expect(problems).toEqual([])
})

test('Gouvernance, Support, Création — menus et pages selon les droits, sans refus inattendu', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  for (const [email, path, forbidden] of [
    ['gov-mgr@leezr.com', '/platform/catalog/modules', '/platform/billing/overview'],
    ['support-only@leezr.com', '/platform/communications/support', '/platform/billing/overview'],
    ['creator842@platform.test', '/platform/companies/create', '/platform/billing/overview'],
  ]) {
    const ctx = await page.context().browser().newContext()
    const p = await ctx.newPage()
    p.setDefaultTimeout(15000)
    const problems = watch(p)
    await loginPlatform(p, email, PASSWORD)
    await open(p, path)
    await expect(p.getByTestId('platform-forbidden')).toHaveCount(0)
    await p.screenshot({ path: `${OUT}/apres-E-${email.split('@')[0]}.jpg`, type: 'jpeg', quality: 60 })
    await open(p, forbidden)
    await expect(p.getByTestId('platform-forbidden')).toBeVisible()
    expect(problems, email).toEqual([])
    await ctx.close()
  }
})
