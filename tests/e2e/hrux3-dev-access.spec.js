// @ts-check
// W-HR-UX-3 — créer un collaborateur via UI → depuis la fiche, DONNER ACCÈS (checklist) → dialog → invitation →
// état "invité". Le P0 (accès depuis la fiche) sans passer par Members.
// ADR-831 (D-21 bis) — portée sur la fiche Collaborateur (ADR-802/777) : même checklist, même dialogue
// d'accès (EmployeeAccessDialog réutilisé) ; clone marqué + registre d'exécution au lieu du DEV réel.
import { test, expect } from '@playwright/test'
import { apiRequest, createEmployeeViaTeam, loginCompany, personFile } from './helpers'
import { createRun } from './run-ledger.js'

const run = createRun('hrux3')

test.afterAll(() => { console.log('teardown', JSON.stringify(run.teardown())) })

test('DEV: grant portal access from fiche checklist', async ({ page }, testInfo) => {
  test.setTimeout(120000)
  await loginCompany(page, 'owner@leezr.test')
  run.useCompany((await apiRequest(page, { path: '/api/profile/space' })).json.company_id)
  const email = run.email('lucie')
  const resp = await createEmployeeViaTeam(page, { firstName: 'Lucie', lastName: run.name('Durand'), email })
  expect(resp.status()).toBe(201)
  const id = (await resp.json()).id
  run.track('employee', id)
  await expect(page).toHaveURL(new RegExp(`/company/people/employee-${id}`), { timeout: 15000 })
  await page.waitForTimeout(1500)

  // Checklist → étape "Accès à l'espace collaborateur" → bouton "Faire"
  const accessRow = page.locator('[data-test="person-onboarding"] .v-list-item', { hasText: /Accès à l.espace collaborateur/i })
  await accessRow.getByRole('button', { name: /Faire/i }).click()
  await page.waitForTimeout(1500)
  await page.screenshot({ path: testInfo.outputPath('hrux3-access-dialog.png') })
  // Dialog "Accès à l'espace collaborateur" → Donner accès
  const dlg = page.locator('.v-dialog').filter({ hasText: /Accès à l.espace collaborateur/i })
  await expect(dlg).toBeVisible({ timeout: 8000 })
  const [grant] = await Promise.all([
    page.waitForResponse(r => /\/access$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 15000 }),
    dlg.getByRole('button', { name: /Donner accès/i }).click(),
  ])
  console.log('GRANT_STATUS=' + grant.status())
  expect(grant.status()).toBeLessThan(400)
  const granted = await grant.json().catch(() => ({}))
  run.track('membership', granted?.membership?.id ?? granted?.membership_id)
  run.track('user', granted?.user?.id ?? granted?.user_id)
  // État "invité" affiché
  await expect(dlg.getByText(/Invitation envoyée/i)).toBeVisible({ timeout: 8000 })
  await page.screenshot({ path: testInfo.outputPath('hrux3-access-invited.png') })
  await dlg.getByRole('button', { name: /Fermer/i }).click()
  await page.waitForTimeout(1500)
  // Persistance : la fiche relue porte un compte (invitation en attente), pas d'accès actif inventé.
  const file = await personFile(page, `employee-${id}`)
  expect(file.user_id, 'compte rattaché après invitation').toBeTruthy()
  run.track('user', file.user_id)
  run.track('membership', file.membership_id)
})
