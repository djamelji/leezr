// @ts-check
// ADR-768 (M7 LOT 2) — parcours NAVIGATEUR réels de la fiche Collaborateur opérationnelle.
// Chaque mutation est déclenchée par l'interface, vérifiée par la réponse de l'API réelle, puis
// RELUE après rechargement (persistance) — une capture seule ne prouve rien.
import { expect, test } from '@playwright/test'
import { apiRequest, clickAndExpectApi, loginCompany } from './helpers.js'
import { createRun } from './run-ledger.js'

const DIR = 'tests/e2e/__screens__/m7-lot2'
// ADR-770 : données possédées par CETTE exécution (identifiant unique + registre) ; le démontage ne
// supprime que les lignes inscrites au registre, après preuve de propriété.
const run = createRun('m7-lot2')
const stamp = run.runId

test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })

async function settle(page, ms = 1200) {
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(ms)
}
const openTab = async (page, name) => {
  await page.getByRole('tab', { name }).first().click()
  await page.waitForTimeout(700)
}
const person = async (page, ref) => (await apiRequest(page, { path: `/api/company/people/${ref}` })).json
const findRef = async (page, search) => {
  const res = await apiRequest(page, { path: `/api/company/people?search=${encodeURIComponent(search)}` })

  return res.json?.data?.[0]?.person_ref || null
}
async function setPickerDate(page, testid, iso) {
  const input = page.locator(`[data-test="${testid}"] input`).first()

  await input.waitFor({ state: 'attached', timeout: 10000 })
  await input.evaluate((el, d) => { el._flatpickr.setDate(d, true) }, iso)
}

test.describe.configure({ mode: 'serial' })

test('compte : inviter → éditer identité → rôle → suspendre → réactiver → retirer (depuis People)', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const email = run.email('people')

  await loginCompany(page, 'owner@leezr.test')
  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await settle(page)

  // ─── Inviter EN PLACE (plus de renvoi vers /company/members) ───
  await page.locator('[data-test="people-new"]').click()
  await page.locator('[data-test="people-invite"]').click()
  await page.getByLabel('Prénom').fill('E2E')
  await page.getByLabel('Nom', { exact: true }).fill('People')
  await page.getByLabel('E-mail').fill(email)
  await page.locator('[data-test="member-role-select"]').click()
  await page.getByRole('option', { name: 'Driver' }).first().click()
  const created = await clickAndExpectApi(page, { urlIncludes: '/api/company/members', method: 'POST' },
    () => page.getByRole('button', { name: 'Ajouter', exact: true }).click())
  const createdBody = await created.json()
  run.track('membership', createdBody.member.id)
  run.track('user', createdBody.member.user.id)
  expect(createdBody.pending_acceptance, 'nouveau compte : parcours historique').toBe(false)
  expect(page.url(), 'on reste sur Collaborateurs').toContain('/company/people')

  const ref = await findRef(page, email)
  expect(ref, 'la personne est dans la liste unifiée').toMatch(/^user-\d+$/)
  await page.getByPlaceholder('Rechercher un collaborateur…').fill(email)
  await expect(page.locator('tr', { hasText: email }), 'visible dans la liste (recherche)').toBeVisible({ timeout: 10000 })

  // ─── Fiche : état d'invitation, puis édition de l'identité ───
  await page.goto(`/company/people/${ref}`, { waitUntil: 'networkidle' })
  await settle(page)
  await expect(page.locator('[data-test="person-account-state"]')).toContainText('Invitation en attente')

  await openTab(page, 'Informations')
  await page.getByLabel('Nom', { exact: true }).fill(`Renamed${stamp}`)
  await clickAndExpectApi(page, { urlIncludes: '/api/company/members/', method: 'PUT' },
    () => page.locator('[data-test="member-profile-save"]').click())
  await page.reload({ waitUntil: 'networkidle' })
  await settle(page)
  await expect(page.locator('[data-test="person-header"]'), 'identité persistée').toContainText(`Renamed${stamp}`)

  // ─── Rôle (MembershipSecurityPolicy côté serveur) ───
  await openTab(page, 'Accès & autorisations')
  await page.locator('[data-test="person-role-select"]').click()
  await page.getByRole('option', { name: 'Manager', exact: true }).first().click()
  await clickAndExpectApi(page, { urlIncludes: '/api/company/members/', method: 'PUT' },
    () => page.locator('[data-test="person-role-save"]').click())
  expect((await person(page, ref)).account.role.name, 'rôle persisté').toBe('Manager')

  // ─── Suspendre → réactiver ───
  await page.locator('[data-test="person-suspend"]').click()
  await page.locator('[data-test="person-suspend-reason"] input').fill('Recette E2E')
  await clickAndExpectApi(page, { urlIncludes: '/suspend', method: 'POST' },
    () => page.locator('[data-test="person-suspend-confirm"]').click())
  await settle(page)
  await expect(page.locator('[data-test="person-account-state"]')).toContainText('Accès suspendu')
  expect((await person(page, ref)).account.has_access).toBe(false)
  await page.screenshot({ path: `${DIR}/04-access-suspended-light.png`, fullPage: true })

  await clickAndExpectApi(page, { urlIncludes: '/restore', method: 'POST' },
    () => page.locator('[data-test="person-restore"]').click())
  await settle(page)
  expect((await person(page, ref)).account.state, 'accès rétabli (invitation toujours en attente)').toBe('invited')

  // ─── Retirer l'accès → retour à la liste, la personne n'y est plus ───
  await clickAndExpectApi(page, { urlIncludes: '/api/company/members/', method: 'DELETE' }, async () => {
    await page.locator('[data-test="person-remove"]').click()
    await page.getByRole('button', { name: 'Confirm' }).click()
  })
  await page.waitForURL(/\/company\/people(\?.*)?$/, { timeout: 15000 })
  expect(await findRef(page, email), 'accès retiré : plus dans la liste').toBeNull()
})

test('salarié : créer sans compte → éditer RH → contrat → chevauchement refusé → rattacher à un compte', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  const lastName = `Sanscompte${stamp}`
  const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10)

  await loginCompany(page, 'owner@leezr.test')
  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await settle(page)

  // ─── Créer une fiche salarié SANS compte, en place ───
  await page.locator('[data-test="people-new"]').click()
  await page.locator('[data-test="people-create-employee"]').click()
  await page.locator('[data-test="emp-first-name"] input').fill('Lina')
  await page.locator('[data-test="emp-last-name"] input').fill(lastName)
  const [resp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('/workforce/employees') && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="emp-submit"]').click(),
  ])
  expect(resp.status()).toBe(201)
  const employeeId = (await resp.json()).id
  run.track('employee', employeeId)
  const ref = `employee-${employeeId}`

  await page.waitForURL(new RegExp(`/company/people/${ref}`), { timeout: 15000 })
  await settle(page)
  await expect(page.locator('[data-test="person-account-state"]'), 'aucun compte inventé').toContainText('Aucun compte')
  await expect(page.locator('[data-test="person-onboarding"]'), "checklist d'intégration reprise").toBeVisible()
  const file = await person(page, ref)
  expect(file.user_id, 'Employee sans User').toBeNull()
  expect(file.membership_id).toBeNull()

  // ─── Éditer les informations RH (matricule) ───
  await page.locator('[data-test="person-edit"]').click()
  await page.waitForTimeout(900)
  await page.getByLabel('Matricule').fill(`M-${stamp}`)
  await Promise.all([
    page.waitForResponse(r => r.url().includes(`/workforce/employees/${employeeId}`) && r.request().method() === 'PUT', { timeout: 20000 }),
    page.getByRole('button', { name: /enregistrer|sauvegarder/i }).first().click(),
  ])
  await settle(page)
  expect((await person(page, ref)).employment.identity.employee_number, 'matricule persisté').toBe(`M-${stamp}`)

  // ─── Contrat : création par l'interface, puis chevauchement refusé proprement ───
  await openTab(page, 'Contrat')
  await page.locator('[data-test="contract-add"]').click()
  await setPickerDate(page, 'contract-start-date', tomorrow)
  const [c] = await Promise.all([
    page.waitForResponse(r => r.url().includes(`/workforce/employees/${employeeId}/contracts`) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="contract-submit"]').click(),
  ])
  expect(c.status()).toBe(201)
  await settle(page)

  const overlap = await apiRequest(page, {
    method: 'POST', path: `/api/workforce/employees/${employeeId}/contracts`,
    body: { contract_type: 'cdi', work_model_key: 'horaire', start_date: tomorrow, weekly_hours: 35 },
  })
  expect(overlap.status, 'chevauchement = erreur métier, plus de 500').toBe(422)
  expect(overlap.json.errors.start_date[0]).toMatch(/overlap/i)
  const contracts = await apiRequest(page, { path: `/api/workforce/employees/${employeeId}/contracts` })
  expect(contracts.json.length, 'un seul contrat persisté').toBe(1)
  await page.screenshot({ path: `${DIR}/05-contract-light.png`, fullPage: true })

  // ─── Anciennes routes : redirection avec deep-link conservé ───
  await page.goto(`/company/workforce/employees/${employeeId}?tab=contract`, { waitUntil: 'networkidle' })
  await page.waitForURL(new RegExp(`/company/people/${ref}\\?tab=contract`), { timeout: 15000 })
  await expect(page.locator('[data-test="contract-add"]'), 'onglet demandé conservé').toBeVisible({ timeout: 10000 })
  await page.goto(`/company/workforce/employees/${employeeId}?legacy=1`, { waitUntil: 'networkidle' })
  await settle(page)
  expect(page.url(), 'interface historique conservée sur demande').toContain(`/company/workforce/employees/${employeeId}`)

  const members = await apiRequest(page, { path: '/api/company/members' })
  const someone = members.json.members.find(m => m.role !== 'owner')
  await page.goto(`/company/members/${someone.id}`, { waitUntil: 'networkidle' })
  await page.waitForURL(/\/company\/people\/(user|employee)-\d+/, { timeout: 15000 })
  const resolved = await apiRequest(page, { path: `/api/company/people/resolve?membership_id=${someone.id}` })
  expect(page.url(), 'id Membership résolu côté serveur').toContain(`/company/people/${resolved.json.person_ref}`)

  // ─── Rattachement EXPLICITE à un compte existant (aucun rapprochement automatique) ───
  const candidates = await apiRequest(page, { path: '/api/workforce/employees/link-candidates' })
  test.skip(!candidates.json?.candidates?.length, 'aucun membre sans fiche salarié sur cette base')
  const target = candidates.json.candidates[0]

  await page.goto(`/company/people/${ref}`, { waitUntil: 'networkidle' })
  await settle(page)
  // Barre d'actions adaptative : selon la largeur disponible (nom long), l'action est directe ou
  // repliée dans le menu « ⋮ ».
  const direct = page.locator('[data-test="person-link-account"]')
  if (await direct.isVisible().catch(() => false)) {
    await direct.click()
  }
  else {
    await page.locator('[data-test="person-header"] button:has(.tabler-dots-vertical)').locator('visible=true').first().click()
    await page.locator('.v-overlay-container .v-list-item', { hasText: 'Rattacher à un compte' }).first().click()
  }
  await page.locator('[data-test="hr-link-account"]').click()
  await page.getByRole('option', { name: new RegExp(target.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).first().click()
  await clickAndExpectApi(page, { urlIncludes: `/workforce/employees/${employeeId}/link-account`, method: 'POST' },
    () => page.locator('[data-test="hr-link-submit"]').click())
  await settle(page)
  const linked = await person(page, ref)
  expect(linked.user_id, 'lien persisté').toBe(target.user_id)
  expect(linked.employment.identity.last_name, 'identité RH non réécrite').toBe(lastName)
})

test('compte existant : invitation EN ATTENTE, jamais activée sans acceptation', async ({ page, browser }) => {
  test.setTimeout(3 * 60 * 1000)
  const existing = process.env.E2E_EXISTING_ACCOUNT || 'trial@leezr.test'

  await loginCompany(page, 'owner@leezr.test')
  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)
  await page.goto('/company/people', { waitUntil: 'networkidle' })
  await settle(page)
  test.skip(!!(await findRef(page, existing)), `${existing} est déjà membre de cette entreprise`)

  await page.locator('[data-test="people-new"]').click()
  await page.locator('[data-test="people-invite"]').click()
  await page.getByLabel('Prénom').fill('Compte')
  await page.getByLabel('Nom', { exact: true }).fill('Existant')
  await page.getByLabel('E-mail').fill(existing)
  await page.locator('[data-test="member-role-select"]').click()
  await page.getByRole('option', { name: 'Driver' }).first().click()
  const res = await clickAndExpectApi(page, { urlIncludes: '/api/company/members', method: 'POST' },
    () => page.getByRole('button', { name: 'Ajouter', exact: true }).click())
  const body = await res.json()
  expect(body.pending_acceptance, 'compte existant : en attente').toBe(true)

  const ref = await findRef(page, existing)
  await page.goto(`/company/people/${ref}?tab=access`, { waitUntil: 'networkidle' })
  await settle(page)
  await expect(page.locator('[data-test="person-account-state"]')).toContainText("En attente d'acceptation")
  expect((await person(page, ref)).account.has_access, 'aucun accès activé').toBe(false)
  await expect(page.locator('[data-test="person-restore"]'), "un admin ne peut pas forcer l'activation").toHaveCount(0)
  const forced = await apiRequest(page, { method: 'POST', path: `/api/company/members/${body.member.id}/restore` })
  expect(forced.status).toBe(422)
  await page.screenshot({ path: `${DIR}/06-pending-acceptance-light.png`, fullPage: true })

  // ─── Le destinataire voit l'invitation et la refuse (nettoyage inclus) ───
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } })
  const guest = await ctx.newPage()
  let declined = false
  try {
    await loginCompany(guest, existing)
    await settle(guest, 2000)
    const card = guest.locator('[data-test="pending-invitations"]')
    await expect(card, 'invitation visible côté destinataire').toBeVisible({ timeout: 15000 })
    await guest.screenshot({ path: `${DIR}/07-recipient-invitation-light.png`, fullPage: true })
    await clickAndExpectApi(guest, { urlIncludes: '/decline', method: 'POST' },
      () => guest.locator('[data-test="invitation-decline"]').first().click())
    declined = true
  }
  finally {
    await ctx.close()
    // Si le destinataire n'a pas pu refuser, l'invitation est annulée par l'administrateur.
    if (!declined) await apiRequest(page, { method: 'DELETE', path: `/api/company/members/${body.member.id}` })
  }
  expect(await findRef(page, existing), 'refus : plus aucun rattachement').toBeNull()
})

test('captures — liste et fiche (desktop/mobile, clair/sombre)', async ({ page }) => {
  test.setTimeout(3 * 60 * 1000)
  await loginCompany(page, 'owner@leezr.test')

  const list = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  const linked = list.json.data.find(r => r.employee_id && r.user_id && r.account_state) || list.json.data.find(r => r.employee_id)
  const ref = linked.person_ref

  for (const scheme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: scheme })
    await page.setViewportSize({ width: 1440, height: 1100 })
    await page.goto('/company/people', { waitUntil: 'networkidle' })
    await settle(page, 1600)
    await page.screenshot({ path: `${DIR}/01-list-${scheme}.png`, fullPage: true })

    await page.goto(`/company/people/${ref}`, { waitUntil: 'networkidle' })
    await settle(page, 1600)
    await page.screenshot({ path: `${DIR}/02-file-overview-${scheme}.png`, fullPage: true })
    for (const [tab, name] of [['access', '03-file-access'], ['employment', '08-file-employment'], ['contract', '09-file-contract'], ['documents', '10-file-documents']]) {
      await page.goto(`/company/people/${ref}?tab=${tab}`, { waitUntil: 'networkidle' })
      await settle(page, 1600)
      await page.screenshot({ path: `${DIR}/${name}-${scheme}.png`, fullPage: true })
    }

    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/company/people', { waitUntil: 'networkidle' })
    await settle(page, 1600)
    await page.screenshot({ path: `${DIR}/11-list-mobile-${scheme}.png`, fullPage: true })
    await page.goto(`/company/people/${ref}`, { waitUntil: 'networkidle' })
    await settle(page, 1600)
    await page.screenshot({ path: `${DIR}/12-file-mobile-${scheme}.png`, fullPage: true })
    await page.goto(`/company/people/${ref}?tab=access`, { waitUntil: 'networkidle' })
    await settle(page, 1600)
    await page.screenshot({ path: `${DIR}/13-file-access-mobile-${scheme}.png`, fullPage: true })
  }
})
