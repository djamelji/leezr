// @ts-check
// ADR-770 (M7 LOT 4) — recette transversale NAVIGATEUR : routes et deep-links, profils réels,
// responsive et accessibilité. Le réseau de chaque parcours est classifié (attendu / refus de
// sécurité déclaré / inattendu) : aucun statut n'est ignoré pour obtenir un test vert.
//
// Profils disponibles sur la base locale (seeders dev, mot de passe défini) :
//   owner@leezr.test (propriétaire, aussi salarié) · admin@leezr.test (rôle administratif, aussi
//   salarié) · bob@leezr.test (salarié, rôle opérationnel) · trial@leezr.test (entreprise sans
//   Workforce). Les six autres profils de la matrice RBAC sont prouvés par appels HTTP directs
//   (M7Lot4RbacMatrixTest) — leurs comptes de recette locaux n'ont pas de mot de passe.
import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { recipeEval } from './recipe.js'
import { apiRequest, loginCompany, watchNetwork } from './helpers.js'

const DIR = 'tests/e2e/__screens__/m7-lot4'

async function settle(page, ms = 1500) {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(ms)
}
// Cadence : chaque chargement complet rappelle les endpoints publics de démarrage (thème, marché,
// plans), limités à 30 requêtes/minute. Une recette automatisée qui recharge la page toutes les
// 2 secondes dépasse cette limite qu'aucun utilisateur n'atteint ; on espace donc les chargements
// (≥ 5 s) au lieu d'ignorer les 429 — un 429 restant serait compté comme une erreur inattendue.
let lastVisit = 0
async function visit(page, path, ms) {
  const wait = 5000 - (Date.now() - lastVisit)
  if (wait > 0) await page.waitForTimeout(wait)
  lastVisit = Date.now()
  await page.goto(path, { waitUntil: 'domcontentloaded' })
  await settle(page, ms)
}

/** Débordement horizontal de la page (hors conteneurs à défilement interne assumé). */
const overflowX = page => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)

/** Indicateurs de chargement INDÉTERMINÉS encore actifs après stabilisation (les jauges fixes ne comptent pas). */
const stuckLoaders = page => page.locator('.v-skeleton-loader:visible, .v-progress-linear__indeterminate:visible, .v-progress-circular--indeterminate:visible').count()

test.describe.configure({ mode: 'serial' })

test('routes : anciennes routes, chemins nus, deep-links et historique (propriétaire)', async ({ page }) => {
  test.setTimeout(8 * 60 * 1000)
  const net = watchNetwork(page)

  await loginCompany(page, 'owner@leezr.test')
  const people = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  const linked = people.json.data.find(r => r.employee_id && r.user_id)
  const members = await apiRequest(page, { path: '/api/company/members' })
  const someone = members.json.members.find(m => m.role !== 'owner')
  const resolved = await apiRequest(page, { path: `/api/company/people/resolve?membership_id=${someone.id}` })

  /** @type {Array<[string, RegExp, string]>} */
  const cases = [
    // Redirections à parité prouvée.
    ['/company/roles', /\/company\/people\/roles$/, 'Rôles → onglet de Collaborateurs'],
    ['/company/workforce/documents', /\/company\/documents\/people/, 'Documents RH → espace Documents'], // ADR-802 : registre RH = /documents/people
    [`/company/members/${someone.id}`, new RegExp(`/company/people/${resolved.json.person_ref}$`), 'fiche membre → fiche unifiée (id résolu serveur)'],
    [`/company/workforce/employees/${linked.employee_id}?tab=contract`, new RegExp(`/company/people/employee-${linked.employee_id}/contract$`), 'fiche salarié → fiche unifiée, onglet conservé'],
    ['/account-settings/documents', /\/company\/my-space\/documents$/, 'mes documents → Mon espace'],
    // Chemins nus.
    ['/company/my-space', /\/company\/my-space\/overview$/, 'Mon espace sans onglet'],
    ['/company/documents', /\/company\/documents\/todo$/, 'Documents sans onglet'], // UX3 (ADR-778) : « À faire » est la vue par défaut
    ['/account-settings', /\/account-settings\/account$/, 'paramètres du compte sans onglet'],
    // Surfaces historiques CONSERVÉES (fonctions sans équivalent).
    ['/company/members', /\/company\/people$/, 'liste membres → Équipe'], // ADR-802 : une fonction, un chemin
    ['/company/workforce/employees', /\/company\/people$/, 'liste salariés → Personnes (outils RH inclus)'], // ADR-802 + ADR-864 : une seule interface
    ['/company/people/hr', /\/company\/people$/, 'ancienne vue Gestion RH → Personnes'], // ADR-864
    [`/company/members/${someone.id}?legacy=1`, new RegExp(`/company/members/${someone.id}\\?legacy=1$`), 'ancienne fiche membre sur demande'],
    // Surfaces cibles.
    ['/company/people', /\/company\/people$/, 'Collaborateurs'],
    ['/company/documents/hr', /\/company\/documents\/people$/, 'Documents RH'], // ADR-802 : registre RH = /documents/people
    ['/home', /\/home$/, 'accueil'],
    ['/company/workforce/me', /\/company\/workforce\/me$/, 'Mon activité'],
  ]

  for (const [path, expected, label] of cases) {
    await visit(page, path)
    await expect(page, label).toHaveURL(expected, { timeout: 30000 })
    await expect(page.locator('body'), `${label} : pas de page d'erreur`).not.toContainText(/Page introuvable|Not Found|Accès refusé/i)
  }

  // Fonctions encore propres aux listes historiques : elles doivent rester atteignables.
  await visit(page, '/company/workforce/employees')
  await expect(page.locator('[data-test="people-add"]').first(), 'Équipe : création (ADR-802)').toBeVisible()
  if (await page.locator('[data-test="emp-more"]').count()) await page.locator('[data-test="emp-more"]').first().click()
  for (const testId of ['emp-import', 'emp-export'])
    await expect(page.locator(`[data-test="${testId}"]`).first(), `liste salariés : action ${testId}`).toBeVisible({ timeout: 10000 })
  await page.keyboard.press('Escape')
  await visit(page, '/company/people')
  // ADR-858 : « Outils → Champs » est retiré — la configuration des champs s'ouvre dans Company › Catalogue
  await page.locator('[data-test="people-configure-fields"]').click()
  await expect(page).toHaveURL(/\/company\/catalogs\/fields/)

  // Historique : liste → fiche → onglet → retour navigateur.
  await visit(page, '/company/people')
  await page.locator('tbody tr').first().click() // ADR-858 : la ligne ouvre l'aperçu rapide, puis la fiche
  await page.locator('[data-test="person-preview-open"]').click()
  await expect(page).toHaveURL(/\/company\/people\/(user|employee)-\d+/, { timeout: 20000 })
  await settle(page)
  await page.getByRole('tab', { name: 'Informations & emploi' }).first().click() // ADR-777 : rubrique
  await expect(page).toHaveURL(/\/profile$/) // ADR-820 : rubrique dans le chemin
  await page.locator('[data-test="page-back"], [data-test="person-back"]').first().click() // ADR-862 : the way back sits in the page header
  await expect(page, 'retour à la liste').toHaveURL(/\/company\/people$/, { timeout: 20000 })

  net.assertClean('routes (propriétaire)')
})

test('profil salarié opérationnel : son espace, et des refus de sécurité EXPLICITES ailleurs', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)

  // Le rôle local de ce compte (« Driver », non administratif) détient members.view, members.invite et
  // documents.view, mais aucune permission Workforce, paie ou signature. Refus ATTENDUS : il sonde
  // volontairement ce que ce rôle n'autorise pas.
  const net = watchNetwork(page, {
    expectedRefusals: [
      { status: 403, method: 'GET', path: /^\/api\/workforce\/employees/, reason: 'pas workforce.view' },
      { status: 403, method: 'GET', path: /^\/api\/company\/payslip-imports/, reason: 'pas payslips.*' },
      { status: 403, method: 'GET', path: /^\/api\/workforce\/signature$/, reason: 'pas workforce_signature.view' },
      { status: 403, method: 'GET', path: /^\/api\/company\/people\/[a-z]+-\d+\/payslips/, reason: "bulletins d'autrui : autorité paie" },
      { status: 403, method: 'GET', path: /^\/api\/company\/roles$/, reason: 'pas roles.view' },
      { status: 403, method: 'PUT', path: /^\/api\/company\/members\/\d+$/, reason: 'pas members.manage' },
      { status: 403, method: 'POST', path: /^\/api\/company\/members\/\d+\/suspend$/, reason: 'pas members.manage' },
      { status: 422, method: 'POST', path: /^\/api\/company\/members$/, reason: 'anti-escalade : rôle administratif refusé à un non-administrateur' },
      { status: 403, method: 'POST', path: /^\/api\/company\/members$/, reason: 'pas de droit d\'inviter (ADR-830 : refus plus strict que l\'anti-escalade)' },
    ],
  })

  await loginCompany(page, 'bob@leezr.test')
  const space = await apiRequest(page, { path: '/api/profile/space' })
  expect(space.json.has_employee).toBe(true)

  // Parcours autorisé : aucune erreur.
  for (const path of ['/home', '/company/my-space/overview', '/company/my-space/documents', '/company/my-space/payslips', '/company/my-space/signatures', '/company/workforce/me', '/account-settings/account', '/account-settings/security'])
    await visit(page, path)
  await expect(page.getByRole('link', { name: 'Mon espace' }).first()).toBeVisible()
  net.assertClean('parcours autorisé du salarié')
  expect(net.refusals, 'aucun refus sur le parcours autorisé').toEqual([])

  // Ce que son rôle autorise : la facette ACCÈS seulement — aucune donnée RH dans les réponses.
  const list = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  expect(list.status).toBe(200)
  expect(list.json.facets, 'facette ACCÈS sans facette RH').toMatchObject({ access: true, hr: false }) // ADR-816 : account_details en plus
  expect(list.json.data.every(r => r.employment_status === null && r.job_role === null && r.department === null), 'aucune colonne RH').toBe(true)
  const other = list.json.data.find(r => r.user_id && r.email !== 'bob@leezr.test' && !r.is_owner)
  const file = await apiRequest(page, { path: `/api/company/people/${other.person_ref}` })
  expect([file.status, file.json.employment, file.json.can.view_hr, file.json.can.edit_identity]).toEqual([200, null, false, false])
  // ADR-830 (D-21) : the probe needs an administrative role id ; bob cannot list roles (403, asserted below)
  // and the people list only exposes the roles of the rows visible to him — read it through the guarded recipe.
  const adminRole = list.json.data.map(r => r.role).find(r => r?.is_administrative)
    || { id: Number(recipeEval("echo App\\Company\\RBAC\\CompanyRole::where('company_id',1)->where('is_administrative',true)->value('id');")) }
  expect(adminRole.id, 'an administrative role exists').toBeGreaterThan(0)

  // Appels HTTP directs (ce que l'interface masque) : refusés par le serveur.
  // ADR-816 hides account details (membership id) from non-admins — read a real one on the marked clone so the
  // write probes below still target an existing membership (ADR-830 D-21).
  const membershipId = file.json.membership_id || Number(recipeEval("$b = App\\Core\\Models\\User::where('email','bob@leezr.test')->value('id'); echo App\\Core\\Models\\Membership::where('company_id',1)->where('role','!=','owner')->where('user_id','!=',$b)->value('id');"))
  expect(membershipId, 'a target membership exists').toBeGreaterThan(0)
  const probes = [
    ['GET', '/api/workforce/employees', null, 403],
    ['GET', '/api/company/payslip-imports', null, 403],
    ['GET', '/api/workforce/signature', null, 403],
    ['GET', `/api/company/people/${other.person_ref}/payslips`, null, 403],
    ['GET', '/api/company/roles', null, 403],
    ['PUT', `/api/company/members/${membershipId}`, { last_name: 'Hijack' }, 403],
    ['POST', `/api/company/members/${membershipId}/suspend`, {}, 403],
    ['POST', '/api/company/members', { email: `escalade.${Date.now()}@leezr.test`, first_name: 'E', last_name: 'S', company_role_id: adminRole.id }, [403, 422]], // ADR-830 : no invite right (403) or escalation refused (422) — never created
  ]
  for (const [method, path, body, expected] of probes) {
    const res = await apiRequest(page, { method, path, body })

    if (Array.isArray(expected)) expect(expected, `${method} ${path} → ${res.status}`).toContain(res.status)
    else expect(res.status, `${method} ${path}`).toBe(expected)
  }
  expect(net.refusals.length, 'chaque refus est un refus de sécurité déclaré').toBe(probes.length)
  net.assertClean('sondes du salarié')

  // Ses propres données restent accessibles.
  for (const path of ['/api/profile/payslips', '/api/profile/documents', '/api/workforce/signature/me', '/api/workforce/me'])
    expect((await apiRequest(page, { path })).status, path).toBe(200)

  await page.screenshot({ path: `${DIR}/01-employee-my-space.png`, fullPage: true })
})

const SURFACES = [
  ['people', '/company/people'],
  ['person', null], // fiche résolue à l'exécution
  ['documents-overview', '/company/documents/overview'],
  ['documents-requests', '/company/documents/requests'],
  ['documents-campaigns', '/company/documents/campaigns'],
  ['documents-payslips', '/company/documents/payslips'],
  ['documents-hr', '/company/documents/hr'],
  ['my-space', '/company/my-space/overview'],
  ['my-payslips', '/company/my-space/payslips'],
  ['my-signatures', '/company/my-space/signatures'],
  ['home', '/home'],
]
const VIEWPORTS = [['desktop', 1440, 1000], ['tablet', 820, 1180], ['mobile', 390, 844]]

test('responsive : aucun débordement, aucun chargement bloqué, clair et sombre', async ({ page }) => {
  test.setTimeout(25 * 60 * 1000)
  const net = watchNetwork(page)
  const findings = []

  await loginCompany(page, 'owner@leezr.test')
  const people = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  const linked = people.json.data.find(r => r.employee_id && r.user_id)
  const surfaces = SURFACES.map(([name, path]) => [name, path || `/company/people/${linked.person_ref}`])

  for (const scheme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: scheme })
    for (const [vp, width, height] of VIEWPORTS) {
      await page.setViewportSize({ width, height })
      for (const [name, path] of surfaces) {
        await visit(page, path, 1800)
        const overflow = await overflowX(page)
        const loaders = await stuckLoaders(page)

        if (overflow > 2) findings.push(`débordement ${overflow}px — ${name} ${vp} ${scheme}`)
        if (loaders > 0) findings.push(`chargement bloqué (${loaders}) — ${name} ${vp} ${scheme}`)
        await page.screenshot({ path: `${DIR}/${name}-${vp}-${scheme}.png`, fullPage: true })
      }
    }
  }

  expect(findings, 'défauts responsive').toEqual([])
  net.assertClean('responsive')
})

test('interactions mobiles : menus, tiroirs, dialogues, onglets, états vides', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  const net = watchNetwork(page)

  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page, 'owner@leezr.test')

  // Collaborateurs : menu « Nouveau » → tiroir d'invitation, fermable, sans débordement.
  await visit(page, '/company/people')
  await page.locator('[data-test="people-add"]').click() // ADR-777 : parcours unique
  await page.locator('[data-test="person-add-access-yes"] input').check()
  await expect(page.locator('[data-test="person-add-email"]')).toBeVisible({ timeout: 10000 })
  expect(await overflowX(page), 'tiroir invitation').toBeLessThanOrEqual(2)
  await page.screenshot({ path: `${DIR}/20-mobile-invite-drawer.png` })
  await page.getByRole('button', { name: /annuler|cancel/i }).first().click()

  // Dialogue de création de salarié : plein écran, actions atteignables.
  await page.locator('[data-test="people-add"]').click()
  await page.locator('[data-test="person-add-access-no"] input').check()
  await expect(page.locator('[data-test="person-add-submit"]')).toBeInViewport({ timeout: 10000 })
  await page.screenshot({ path: `${DIR}/21-mobile-create-employee.png` })
  await page.getByRole('button', { name: /annuler|cancel/i }).first().click()

  // Recherche sans résultat : état vide utile.
  await page.getByPlaceholder('Rechercher un collaborateur…').fill('zzz-aucun-résultat-zzz')
  await expect(page.getByText('Aucun collaborateur.')).toBeVisible({ timeout: 10000 })

  // Fiche : la navigation par sections reste utilisable et chaque section annoncée s'ouvre.
  const people = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  const linked = people.json.data.find(r => r.employee_id && r.user_id)
  const file = await apiRequest(page, { path: `/api/company/people/${linked.person_ref}` })
  const tabs = file.json.available_sections.includes('employment') ? [...file.json.available_sections, 'contract'] : file.json.available_sections
  for (const tab of tabs) {
    await visit(page, `/company/people/${linked.person_ref}?tab=${tab}`, 1200)
    await expect(page.locator('[data-test="person-header"]'), `onglet ${tab}`).toBeVisible()
    expect(await overflowX(page), `fiche ${tab}`).toBeLessThanOrEqual(2)
    expect(await stuckLoaders(page), `fiche ${tab} : chargement terminé`).toBe(0)
  }
  // Un onglet sans capacité n'est pas rendu : retour à la vue d'ensemble, pas d'écran vide.
  await visit(page, `/company/people/${linked.person_ref}?tab=inexistant`, 1200)
  await expect(page.locator('[data-test="person-header"]')).toBeVisible()

  // Mon espace sur mobile.
  await visit(page, '/company/my-space/overview')
  await page.locator('[data-test="my-space-entry-payslips"]').click()
  await expect(page).toHaveURL(/\/company\/my-space\/payslips/)
  await expect(page.locator('[data-test="my-payslips"]')).toBeVisible()

  net.assertClean('interactions mobiles')
})

test('accessibilité : aucune violation critique sur les surfaces principales', async ({ page }) => {
  test.setTimeout(6 * 60 * 1000)
  await loginCompany(page, 'owner@leezr.test')
  const people = await apiRequest(page, { path: '/api/company/people?per_page=100' })
  const linked = people.json.data.find(r => r.employee_id && r.user_id)
  const report = {}
  const critical = []
  const knownFramework = []

  for (const [name, path] of [['people', '/company/people'], ['person', `/company/people/${linked.person_ref}`], ['person-access', `/company/people/${linked.person_ref}?tab=access`],
    ['documents', '/company/documents/overview'], ['my-space', '/company/my-space/overview'], ['my-payslips', '/company/my-space/payslips'], ['my-signatures', '/company/my-space/signatures'], ['home', '/home']]) {
    await visit(page, path, 1800)
    const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()

    report[name] = violations.map(v => `${v.impact}:${v.id}(${v.nodes.length})`)
    for (const v of violations.filter(x => x.impact === 'critical')) {
      for (const node of v.nodes) {
        // Défaut CONNU du framework, déclaré et compté (jamais ignoré en bloc) : le bouton de fermeture
        // de l'en-tête des tiroirs (`@core/components/AppDrawerHeaderSection`, non modifiable par le
        // projet) n'a pas de nom accessible. Tout autre cas critique fait échouer le test.
        const isFrameworkDrawerClose = v.id === 'button-name' && await page.locator(node.target[0]).first()
          .evaluate(el => !!el.closest('.v-navigation-drawer') && !!el.querySelector('.tabler-x')).catch(() => false)

        if (isFrameworkDrawerClose) knownFramework.push(`${name} — ${v.id} (fermeture de tiroir @core)`)
        else critical.push(`${name} — ${v.id} ${node.target.join(' ')}`)
      }
    }
  }
  console.log('AXE', JSON.stringify(report))
  console.log('AXE-KNOWN-FRAMEWORK', JSON.stringify(knownFramework))
  expect(critical, 'violations critiques').toEqual([])
})
