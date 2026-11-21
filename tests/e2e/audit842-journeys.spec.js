// @ts-check
// Leezr 4.2 — AUDIT : complete human journeys with REAL clicks (marked clone only — global-setup, ADR-831).
// They write fictitious data in the clone (new company, invitation, member, ticket…). Each step is recorded
// (ok / failed + reason + screenshot) in data/journeys.json ; a failing step never hides the next journeys.
import fs from 'node:fs'
import { test } from '@playwright/test'
import { loginCompany, loginPlatform } from './helpers.js'

const OUT = 'docs/bmad/leezr-4-2-audit/data/journeys'
// audit campaign only : skipped by a normal E2E run
test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 audit campaign only (AUDIT_PASSWORD_FILE)')
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const RUN = Date.now().toString(36).slice(-5)
const results = []
if (process.env.AUDIT_PASSWORD_FILE)
  fs.mkdirSync(OUT, { recursive: true })

const step = async (page, journey, name, fn) => {
  const started = Date.now()
  const apiErrors = []
  const onResp = r => { if (r.status() >= 400 && r.url().includes('/api/')) apiErrors.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, '').split('?')[0]}`) }
  page.on('response', onResp)
  let ok = true
  let note = null
  try {
    note = await fn()
  }
  catch (e) {
    ok = false
    note = String(e.message || e).split('\n')[0].slice(0, 300)
  }
  page.off('response', onResp)
  const shot = `${OUT}/${journey}-${results.filter(r => r.journey === journey).length + 1}.jpg`
  await page.screenshot({ path: shot, type: 'jpeg', quality: 55 }).catch(() => {})
  results.push({ journey, name, ok, note, apiErrors, ms: Date.now() - started, shot, url: page.url().replace(/^https?:\/\/[^/]+/, '') })
  fs.writeFileSync(`${OUT}/../journeys.json`, JSON.stringify(results, null, 1))

  return ok
}

const mailpitLink = async (to, pattern) => {
  for (let i = 0; i < 10; i++) {
    const list = await (await fetch(`http://127.0.0.1:8025/api/v1/search?query=${encodeURIComponent(`to:${to}`)}`)).json()
    const msg = list.messages?.[0]
    if (msg) {
      const full = await (await fetch(`http://127.0.0.1:8025/api/v1/message/${msg.ID}`)).json()
      const m = (full.HTML || full.Text || '').match(pattern)
      if (m)
        return { subject: msg.Subject, link: m[0].replace(/&amp;/g, '&') }
    }
    await new Promise(r => setTimeout(r, 1500))
  }

  return null
}

const next = page => page.getByTestId('wizard-next').click()

test.beforeEach(({ page }) => { page.setDefaultTimeout(15000) })

test('J1-J3 — Platform creates a company, the owner activates it and starts using it', async ({ page }) => {
  test.setTimeout(15 * 60 * 1000)
  const adminEmail = `owner.${RUN}@audit842.test`
  const companyName = `Audit 842 Services ${RUN}`

  await step(page, 'J1', 'Opérateur création : connexion', () => loginPlatform(page, 'creator842@platform.test', PASSWORD))
  await step(page, 'J1', 'Ouvrir « Nouvelle société » depuis la liste', async () => {
    await page.getByRole('link', { name: /Nouvelle société/ }).or(page.getByRole('button', { name: /Nouvelle société/ })).first().click()
    await page.waitForURL(/companies\/create/)
  })
  await step(page, 'J1', 'Étape Activité : Beta Services', async () => {
    await page.getByText('Beta Services', { exact: true }).first().click()
    await next(page)
  })
  await step(page, 'J1', 'Étape Société : nom + Suivant', async () => {
    await page.getByLabel('Nom commercial').fill(companyName)
    await page.waitForTimeout(800)
    await next(page)
    await page.waitForTimeout(600)
    const box = page.getByTestId('step-issues')
    const issues = (await box.isVisible()) ? await box.textContent() : null
    if (issues)
      throw new Error(`bloqué à l'étape Société : ${issues.replace(/\s+/g, ' ').slice(0, 200)}`)
  })
  await step(page, 'J1', 'Étape Modules : passer', async () => { await page.waitForTimeout(800); await next(page) })
  await step(page, 'J1', 'Étape Abonnement : Starter', async () => {
    await page.getByText('Starter', { exact: true }).first().click()
    await next(page)
  })
  await step(page, 'J1', 'Étape Administrateur', async () => {
    await page.getByLabel('Prénom').fill('Alice')
    await page.getByLabel('Nom', { exact: true }).fill('Audit')
    await page.getByLabel(/E-mail|Adresse e-mail|Email/).first().fill(adminEmail)
    await page.waitForTimeout(1500)
    await next(page)
  })
  await step(page, 'J1', 'Étape Initialisation → Récapitulatif', async () => { await page.waitForTimeout(800); await next(page) })
  await step(page, 'J1', 'Créer la société', async () => {
    await page.getByTestId('create-company-submit').click()
    await page.waitForURL(u => !/create/.test(u.pathname), { timeout: 30000 })
    await page.waitForTimeout(1500)

    return page.url()
  })
  await step(page, 'J1', 'Fiche créée : étape de mise en service visible', async () => {
    const text = await page.locator('.layout-page-content').innerText()
    if (!/mise en service|invit/i.test(text))
      throw new Error('aucune information de mise en service visible')

    return text.match(/(Créée|Administrateur invité|Paiement en attente|Opérationnelle)[^\n]{0,40}/)?.[0] || 'ok'
  })

  // J2 — the administrator receives the activation e-mail (isolated Mailpit) and activates the account
  let activation = null
  await step(page, 'J2', 'E-mail d\'activation reçu (Mailpit)', async () => {
    activation = await mailpitLink(adminEmail, /https?:\/\/[^"'\s<>]+(activate|activation|invitation|reset-password|token)[^"'\s<>]*/i)
    if (!activation)
      throw new Error('aucun e-mail d\'activation reçu')

    return activation.subject
  })
  if (activation) {
    await page.context().clearCookies()
    await step(page, 'J2', 'Lien d\'activation → choisir un mot de passe', async () => {
      await page.goto(activation.link.replace(/^https?:\/\/[^/]+/, ''), { waitUntil: 'networkidle' })
      const pw = page.locator('input[type="password"]')
      await pw.nth(0).fill(PASSWORD)
      if (await pw.count() > 1)
        await pw.nth(1).fill(PASSWORD)
      await page.getByRole('button', { name: /activer|valider|enregistrer|définir|confirmer|réinitialiser/i }).first().click()
      await page.waitForTimeout(3000)

      return page.url()
    })
    await step(page, 'J2', 'Première connexion du propriétaire', async () => {
      if (/login/.test(page.url()))
        await loginCompany(page, adminEmail, PASSWORD)
      await page.waitForTimeout(2000)
      const text = await page.locator('.layout-page-content').innerText()

      return text.slice(0, 160).replace(/\s+/g, ' ')
    })

    // J3 — first steps : modules page, a module outside the plan, invite a collaborator
    await step(page, 'J3', 'Modules : la page explique inclus / option / hors plan', async () => {
      await page.goto('/company/modules', { waitUntil: 'networkidle' })
      await page.waitForTimeout(1500)
      const text = await page.locator('.layout-page-content').innerText()

      return { plan: /plan|forfait|offre/i.test(text), addon: /option|payant|€/i.test(text), upgrade: /passer|mettre à niveau|upgrade|supérieur/i.test(text) }
    })
    await step(page, 'J3', 'Inviter un collaborateur', async () => {
      await page.goto('/company/people', { waitUntil: 'networkidle' })
      await page.getByRole('button', { name: /ajouter|inviter|nouveau/i }).first().click()
      await page.waitForTimeout(1200)
      const dialog = page.locator('.v-overlay--active').last()
      await dialog.getByLabel('Prénom').fill('Bruno')
      await dialog.getByLabel('Nom', { exact: true }).fill('Collab')
      const email = dialog.getByLabel(/e-mail|email/i).first()
      if (await email.count())
        await email.fill(`collab.${RUN}@audit842.test`)
      await dialog.getByRole('button', { name: /ajouter|inviter|créer|enregistrer|continuer/i }).last().click()
      await page.waitForTimeout(2500)

      return (await page.locator('.v-snackbar, .v-alert').allInnerTexts()).join(' | ').slice(0, 200)
    })
  }
})

test('J4 — Salarié demande un congé, le manager le voit à valider', async ({ page }) => {
  test.setTimeout(8 * 60 * 1000)
  await step(page, 'J4', 'Salarié : connexion + Mon espace', async () => {
    await loginCompany(page, 'salarie@leezr.test', PASSWORD)
    await page.goto('/company/my-space/overview', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1200)

    return (await page.locator('.layout-page-content').innerText()).slice(0, 200).replace(/\s+/g, ' ')
  })
  await step(page, 'J4', 'Salarié : ouvrir une demande de congé', async () => {
    await page.goto('/company/workforce/me', { waitUntil: 'networkidle' }).catch(() => {})
    await page.waitForTimeout(1200)
    const btn = page.getByRole('button', { name: /demander|congé|absence/i }).first()
    if (!(await btn.count()))
      throw new Error(`aucune action « demander un congé » visible sur ${page.url().replace(/^https?:\/\/[^/]+/, '')}`)
    await btn.click()
    await page.waitForTimeout(1500)

    return (await page.locator('.v-overlay--active, .v-navigation-drawer--active').last().innerText().catch(() => '')).slice(0, 200).replace(/\s+/g, ' ')
  })
})

test('J5 — Société : ticket de support, vu par Platform', async ({ page }) => {
  test.setTimeout(8 * 60 * 1000)
  const subject = `Audit 842 ticket ${RUN}`
  await step(page, 'J5', 'Propriétaire : créer un ticket', async () => {
    await loginCompany(page, 'owner@leezr.test', PASSWORD)
    await page.goto('/company/support', { waitUntil: 'networkidle' })
    await page.getByRole('button', { name: /nouveau|créer|ouvrir|ticket/i }).first().click()
    await page.waitForTimeout(1000)
    const dialog = page.locator('.v-overlay--active').last()
    await dialog.getByLabel(/sujet|objet|titre/i).first().fill(subject)
    await dialog.locator('textarea').first().fill('Ticket créé par la campagne d\'audit 842 (données fictives).')
    await dialog.getByRole('button', { name: /créer|envoyer|soumettre|valider/i }).last().click()
    await page.waitForTimeout(2500)

    return page.url().replace(/^https?:\/\/[^/]+/, '')
  })
  await step(page, 'J5', 'Platform support : le ticket apparaît', async () => {
    await page.context().clearCookies()
    await loginPlatform(page, 'admin@leezr.com', PASSWORD)
    await page.goto('/platform/communications/support', { waitUntil: 'networkidle' })
    await page.waitForTimeout(1500)
    if (!(await page.getByText(subject).count()))
      throw new Error('ticket absent de la liste Platform')
  })
})

test('J3b — Propriétaire : ajouter une personne avec invitation', async ({ page }) => {
  test.setTimeout(6 * 60 * 1000)
  const email = `collab.${RUN}@audit842.test`
  await step(page, 'J3b', 'Ajouter une personne (panneau) avec invitation', async () => {
    await loginCompany(page, 'pending@leezr.test', PASSWORD)
    await page.goto('/company/people', { waitUntil: 'networkidle' })
    await page.getByRole('button', { name: /Ajouter une personne/ }).click()
    const panel = page.locator('.v-navigation-drawer--active').last()
    await panel.getByLabel(/Prénom/).fill('Bruno')
    await panel.getByLabel(/^Nom/).fill('Collab')
    await panel.getByText(/Oui, elle reçoit une invitation/).click()
    await page.waitForTimeout(800)
    await panel.locator('input[type="email"], input').nth(2).fill(email)
    await page.waitForTimeout(800)
    await panel.locator('.v-field').last().click()
    await page.waitForTimeout(600)
    await page.locator('.v-menu .v-list-item, .v-overlay-container .v-list-item').first().click()
    await panel.getByRole('button', { name: /Ajouter et inviter/ }).click()
    await page.waitForTimeout(2500)

    return (await page.locator('.v-snackbar').allInnerTexts()).join(' | ').slice(0, 200)
  })
  await step(page, 'J3b', 'Invitation reçue (Mailpit)', async () => {
    const m = await mailpitLink(email, /https?:\/\/[^"'\s<>]+/i)
    if (!m) throw new Error('aucun e-mail d\'invitation')

    return m.subject
  })
})
