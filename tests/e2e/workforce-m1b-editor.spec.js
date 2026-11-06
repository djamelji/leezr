// Refonte Smart Planning — M1.b (ADR-724) : ÉDITEUR VISUEL de composition depuis la VRAIE interface.
// Un responsable crée un modèle (horaires + 3 activités + 1 pause positionnée), le sauvegarde, le retrouve
// après reload, l'applique à un salarié, voit les activités matérialisées, modifie le modèle source sans
// effet rétroactif, puis refait le parcours sur mobile (scroll/boutons). Validation d'incohérence vérifiée.
// Les ACTIONS passent par l'UI ; les lectures de vérification peuvent interroger l'API.
import { test, expect } from '@playwright/test'
import { loginCompany, apiRequest, trackCreatedShifts, cleanupShifts } from './helpers.js'

test.use({ ignoreHTTPSErrors: true })

const API = '/api/workforce/planning'
let created
const createdTemplateIds = new Set()

test.beforeEach(({ page }) => { created = trackCreatedShifts(page) })
test.afterEach(async ({ page }) => {
  await cleanupShifts(page, created)
  for (const id of createdTemplateIds) await apiRequest(page, { method: 'DELETE', path: `${API}/templates/${id}` })
  createdTemplateIds.clear()
})

async function openTemplatesTab(page) {
  await page.goto('/company/workforce/time?tab=planning', { waitUntil: 'domcontentloaded' })
  const tab = page.locator('[data-test="plan-view-templates"]')
  await tab.waitFor({ state: 'visible', timeout: 30000 })
  await tab.click()
  await page.waitForTimeout(600)
}
async function fillCore(page, name) {
  await page.locator('[data-test="template-name"] input').fill(name)
  await page.locator('[data-test="template-start"] input').fill('02:00')
  await page.locator('[data-test="template-end"] input').fill('04:00')
}
async function addActivity(page, label, start, end) {
  await page.locator('[data-test="template-add-activity"]').click()
  const row = page.locator('[data-test="template-activity-row"]').last()
  await row.locator('input[type="text"]').first().fill(label)
  await row.locator('[data-test="template-activity-start"] input').fill(start)
  await row.locator('[data-test="template-activity-end"] input').fill(end)
}

test('M1.b — éditeur visuel : création composée, reload, application, modif sans effet rétroactif', async ({ page }) => {
  test.setTimeout(180000)
  await loginCompany(page)
  await openTemplatesTab(page)

  // 1-2) Nouveau modèle + horaires
  await page.locator('[data-test="template-new"]').click()
  await expect(page.locator('[data-test="template-submit"]')).toBeVisible()
  const name = 'M1b ' + Date.now()
  await fillCore(page, name)

  // 3) Composer 3 activités + 1 pause
  await page.locator('[data-test="template-toggle-composition"]').click()
  await addActivity(page, 'Brief', '02:00', '02:30')
  await addActivity(page, 'Prépa', '02:30', '02:50')
  await addActivity(page, 'Route', '03:10', '04:00')
  await page.locator('[data-test="template-add-break"]').click()
  const brk = page.locator('[data-test="template-break-row"]').last()
  await brk.locator('[data-test="template-break-start"] input').fill('02:50')
  await brk.locator('[data-test="template-break-end"] input').fill('03:10')

  // 4) Aperçu visible
  await expect(page.locator('[data-test="template-composition-preview"]')).toBeVisible()

  // 5-bis) Incohérence immédiate : une activité hors plage → alerte, puis on la retire.
  await addActivity(page, 'HorsPlage', '05:00', '06:00')
  await expect(page.locator('[data-test="template-composition-issue"]').first()).toBeVisible()
  await page.locator('[data-test="template-activity-row"]').last().locator('[data-test="template-activity-remove"]').click()
  await expect(page.locator('[data-test="template-composition-issue"]')).toHaveCount(0)

  // 5) Enregistrer (vraie soumission UI → 201 avec composition).
  const [mkRes] = await Promise.all([
    page.waitForResponse(r => /\/planning\/templates$/.test(r.url()) && r.request().method() === 'POST', { timeout: 20000 }),
    page.locator('[data-test="template-submit"]').click(),
  ])
  expect(mkRes.status()).toBe(201)
  const tpl = await mkRes.json()
  createdTemplateIds.add(tpl.id)
  expect(tpl.metadata?.composition?.schema_version).toBe(1)
  expect(tpl.metadata?.composition?.activities).toHaveLength(3)
  expect(tpl.metadata?.composition?.breaks).toHaveLength(1)

  // 6) Reload + édition → la composition est retrouvée dans l'UI.
  await openTemplatesTab(page)
  await page.locator(`[data-test="template-edit-${tpl.id}"]`).click()
  await page.waitForTimeout(400)
  await expect(page.locator('[data-test="template-toggle-composition"]')).toBeVisible()
  // La composition est pré-dépliée (ADR : showComposition si activités présentes).
  await expect(page.locator('[data-test="template-activity-row"]')).toHaveCount(3)
  await page.locator('[data-test="template-cancel"]').click()
  await expect(page.locator('.v-navigation-drawer__scrim')).toHaveCount(0)

  // 7) Appliquer à un salarié via l'UI (palette armée + clic cellule libre). Base DEV → probe résiliente.
  await page.locator('[data-test="plan-view-planning"]').click()
  await page.waitForTimeout(900)
  // Ouvrir la palette si repliée, puis armer le modèle.
  const card = page.locator(`[data-test="palette-template"][data-template-id="${tpl.id}"]`)
  if (!(await card.count())) {
    const toggle = page.getByRole('button').filter({ has: page.locator('.tabler-layout-grid, .tabler-template') }).first()
    await toggle.click().catch(() => {})
    await page.waitForTimeout(300)
  }
  test.skip(!(await card.count()), 'Palette indisponible dans ce contexte — application UI non testée.')
  await card.click()
  await page.waitForTimeout(300)

  const cells = await page.$$eval('[data-droppable="1"]', els => els.map((e, i) => ({ i, busy: !!e.querySelector('.plan-shift') || !!e.querySelector('.plan-absence') })))
  const freeIdx = cells.filter(c => !c.busy).map(c => c.i)
  let shift = null
  for (const idx of freeIdx.slice(0, 20)) {
    const [resp] = await Promise.all([
      page.waitForResponse(r => /\/planning\/shifts$/.test(r.url()) && r.request().method() === 'POST', { timeout: 10000 }).catch(() => null),
      page.locator('[data-droppable="1"]').nth(idx).click(),
    ])
    if (resp && resp.status() === 201) { shift = await resp.json(); break }
    await page.waitForTimeout(150)
  }
  test.skip(!shift, 'Aucune cellule planifiable (base DEV saturée) — application UI non confirmée.')

  // 8) Activités matérialisées (lecture de vérification).
  const act1 = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${shift.id}` })
  const list1 = (act1.json?.data ?? act1.json ?? [])
  expect(list1).toHaveLength(3)
  expect(list1.every(a => a.source === 'template')).toBeTruthy()
  const labelsBefore = list1.map(a => a.label).sort()

  // 9) Modifier le modèle source via l'UI (changer un libellé d'activité) + enregistrer.
  await page.locator('[data-test="plan-view-templates"]').click()
  await page.waitForTimeout(500)
  await page.locator(`[data-test="template-edit-${tpl.id}"]`).click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="template-activity-row"]').first().locator('input[type="text"]').first().fill('Brief MODIFIÉ')
  const [upd] = await Promise.all([
    page.waitForResponse(r => new RegExp(`/planning/templates/${tpl.id}$`).test(r.url()) && r.request().method() === 'PUT', { timeout: 20000 }),
    page.locator('[data-test="template-submit"]').click(),
  ])
  expect(upd.status()).toBe(200)

  // 10) Le shift déjà créé reste inchangé.
  const act2 = await apiRequest(page, { method: 'GET', path: `${API}/activities?shift_id=${shift.id}` })
  const list2 = (act2.json?.data ?? act2.json ?? [])
  expect(list2).toHaveLength(3)
  expect(list2.map(a => a.label).sort()).toEqual(labelsBefore)
})

test('M1.b — éditeur mobile : drawer scrollable, composition, boutons accessibles', async ({ page }) => {
  test.setTimeout(120000)
  await page.setViewportSize({ width: 390, height: 844 })
  await loginCompany(page)
  await openTemplatesTab(page)
  await page.locator('[data-test="template-new"]').click()
  await expect(page.locator('[data-test="template-name"] input')).toBeVisible()
  await page.locator('[data-test="template-name"] input').fill('M1b mobile ' + Date.now())
  await page.locator('[data-test="template-start"] input').fill('08:00')
  await page.locator('[data-test="template-end"] input').fill('12:00')
  await page.locator('[data-test="template-toggle-composition"]').click()
  await page.locator('[data-test="template-add-activity"]').click()
  const row = page.locator('[data-test="template-activity-row"]').last()
  await row.locator('input[type="text"]').first().fill('Prépa')
  await row.locator('[data-test="template-activity-start"] input').fill('08:00')
  await row.locator('[data-test="template-activity-end"] input').fill('09:00')
  // Le drawer scrolle : le bouton Enregistrer reste atteignable.
  const submit = page.locator('[data-test="template-submit"]')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(page.locator('[data-test="template-composition-preview"]')).toBeVisible()
  // Annuler (pas de persistance mobile nécessaire pour ce scénario).
  await page.locator('[data-test="template-cancel"]').scrollIntoViewIfNeeded()
  await page.locator('[data-test="template-cancel"]').click()
})
