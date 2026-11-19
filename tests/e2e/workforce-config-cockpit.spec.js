// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

// LOT D — Workforce configuration cockpit. A manager sees each rule with provenance + mutability,
// edits a configurable rule (persists), while mandatory legal rules are protected server-side and
// an unauthorized employee is refused (ANO-014: real backend permission, not v-can).

// ADR-831 (D-21 bis) — the configuration cockpit was absorbed by the HR settings hub (ADR-639) : /configuration
// redirects to « Temps de travail & pauses », whose « Règles avancées » panel lists the break rules with provenance
// and mutability (the break TARGET is set by the working-time assistant since L7, ADR-797/799, so the configurable
// rule edited here is the break MAXIMUM). Same checks : mandatory legal minimum locked, configurable rule persisted.
test('manager views the cockpit and edits a configurable break rule (persisted)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/configuration', { waitUntil: 'networkidle' })
  await expect(page).toHaveURL(/\/company\/workforce\/settings\/worktime/, { timeout: 10000 })
  const openAdvanced = async () => {
    await page.locator('[data-test="worktime-advanced"] .v-expansion-panel-title').click()
    await page.waitForTimeout(800)
  }
  await openAdvanced()
  const ruleRow = label => page.locator('[data-test="worktime-advanced"] tr', { has: page.getByText(label, { exact: false }) }).first()

  // Legal minimum is mandatory → read-only (locked), value 20, nothing to save.
  const min = ruleRow('Durée minimale de pause')
  await expect(min).toBeVisible({ timeout: 10000 })
  await expect(min.locator('.tabler-lock').first(), 'mandatory rule is locked').toBeVisible()
  await expect(min).toContainText('20')
  await expect(min.locator('input'), 'mandatory rule is not editable').toHaveCount(0)
  await expect(min.getByRole('button', { name: /Enregistrer/ })).toHaveCount(0)

  // Break maximum is configurable → edit and save.
  const value = String(70 + (Date.now() % 20))
  const max = ruleRow('Durée de pause maximale')
  await max.locator('input[type="number"]').fill(value)
  const [resp] = await Promise.all([
    page.waitForResponse(r => /\/workforce\/config\/breaks\/max_duration_minutes$/.test(r.url()) && r.request().method() === 'PUT'),
    max.getByRole('button', { name: /Enregistrer/ }).click(),
  ])
  expect(resp.status(), 'save succeeded').toBeLessThan(400)

  // Persistence: reload → the edited value is served by the resolver.
  await page.reload({ waitUntil: 'networkidle' })
  await openAdvanced()
  await expect(ruleRow('Durée de pause maximale').locator('input[type="number"]')).toHaveValue(value)
})

test('mandatory rules are protected and below-minimum is rejected server-side', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/configuration', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)

  // Cannot override a mandatory legal rule.
  const mand = await apiRequest(page, { method: 'PUT', path: '/api/workforce/config/breaks/min_duration_minutes', body: { value: 45 } })
  expect(mand.status, 'mandatory rule override refused').toBe(422)

  // Cannot set a configurable target below the legal minimum.
  const low = await apiRequest(page, { method: 'PUT', path: '/api/workforce/config/breaks/target_duration_minutes', body: { value: 5 } })
  expect(low.status, 'below-legal-minimum refused').toBe(422)
})

test('an employee without workforce.view is refused the config API (403)', async ({ page }) => {
  await loginCompany(page, 'bob@leezr.test')
  await page.goto('/company/workforce/me', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  const res = await apiRequest(page, { method: 'GET', path: '/api/workforce/config/breaks' })
  expect(res.status, 'employee forbidden from config').toBe(403)
})
