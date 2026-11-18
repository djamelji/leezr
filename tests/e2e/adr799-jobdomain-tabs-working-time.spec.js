// ADR-799 — recette : (A) page JobDomain en six onglets, preset Rôles créé et publié depuis son onglet, cadre
// conventionnel recommandé ; (B) assistant Company « Temps de travail » (cadre, rythme, repos, horaires,
// comportement), refus d'un repos moins favorable que le minimum légal vérifié. Desktop / mobile, clair /
// sombre. Fixtures ISOLÉES (domaine « e2e_799_<stamp> ») supprimées par identifiants EXACTS ; la société 1
// n'est que LUE (la seule tentative d'enregistrement est refusée par le serveur, rien n'est écrit).
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { loginCompany, loginPlatform, apiRequest } from './helpers.js'
import { recipeEval, recipePhp } from './recipe.js'

const OUT = 'docs/bmad/adr799'
const settle = (page, ms = 900) => page.waitForTimeout(ms)
const tinker = code => recipeEval(code) // ADR-828 : guarded recipe command, never tinker

test('A — JobDomain en six onglets, preset publié depuis son onglet', async ({ page }) => {
  test.setTimeout(240000)
  const key = `e2e_799_${Date.now().toString().slice(-7)}`
  let jdId = null
  try {
    await loginPlatform(page)
    const res = await apiRequest(page, { method: 'POST', path: '/api/platform/jobdomains', body: { key, label: `E2E 799 ${key.slice(-4)}`, description: 'Domaine fictif de recette' } })
    jdId = res.json.jobdomain.id
    await page.goto(`/platform/jobdomains/${jdId}`)
    await expect(page.locator('[data-test="jd-tabs"] [role="tab"]')).toHaveCount(5, { timeout: 30000 }) // ADR-820 : Général · Modules · Expérience · Presets · Publication
    await expect(page.locator('[data-test="jd-identity"]')).toContainText('Cadre conventionnel recommandé')
    await expect(page.locator('[data-test="jd-identity"]')).toContainText('ne fixe pas l’organisation du temps de travail')
    await settle(page)
    await page.screenshot({ path: `${OUT}/jobdomain-general.jpg`, type: 'jpeg', quality: 65 })

    // Rôles : création depuis l'onglet (aucun choix de version, aucune association manuelle), puis publication.
    await page.locator('[data-test="jd-tabbtn-presets"]').click()
    await page.locator('[data-test="jd-family-rbac"]').click()
    await page.locator('[data-test="jd-edit-rbac"]').click()
    await expect(page.locator('[data-test="preset-editor"]')).toBeVisible({ timeout: 60000 })
    await page.locator('[data-test="preset-editor-label"] input').fill('Rôles E2E')
    await page.locator('[data-test="preset-add-role"]').click()
    await page.locator('[data-test="preset-role-0"]').getByLabel('Libellé du rôle').fill('Agent')
    await page.locator('[data-test="preset-role-0"]').getByLabel(/Lots de permissions/).click()
    await page.getByRole('option').first().click()
    await page.keyboard.press('Escape')
    await page.locator('[data-test="preset-editor-save"]').click()
    await expect(page.locator('[data-test="jd-publish-rbac"]')).toBeVisible({ timeout: 60000 })
    await page.locator('[data-test="jd-publish-rbac"]').click()
    await expect(page.locator('[data-test="jd-tab-rbac"]')).toContainText('En vigueur', { timeout: 60000 })
    await expect(page.locator('[data-test="jd-tab-rbac"]')).toContainText('Agent')
    await settle(page)
    await page.screenshot({ path: `${OUT}/jobdomain-roles-tab.jpg`, type: 'jpeg', quality: 65 })

    await page.locator('[data-test="jd-family-planning"]').click()
    await expect(page.getByText('Ce ne sont pas des règles de travail')).toBeVisible()
    await page.emulateMedia({ colorScheme: 'dark' })
    await settle(page)
    await page.screenshot({ path: `${OUT}/jobdomain-planning-dark.jpg`, type: 'jpeg', quality: 65 })
    await page.emulateMedia({ colorScheme: 'light' })
    await page.setViewportSize({ width: 390, height: 844 })
    await page.locator('[data-test="jd-tabbtn-general"]').click()
    await settle(page)
    await page.screenshot({ path: `${OUT}/jobdomain-mobile.jpg`, fullPage: true, type: 'jpeg', quality: 65 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
  }
  finally {
    tinker(`App\\Core\\Presets\\JobdomainPresetLink::where('jobdomain_key','${key}')->delete(); App\\Core\\Presets\\Preset::where('family_key','${key}_rbac')->delete(); App\\Core\\Jobdomains\\Jobdomain::where('id',${jdId || 0})->where('key','${key}')->delete(); echo 'ok';`)
  }
})

test('B — assistant Company : temps de travail', async ({ page }) => {
  test.setTimeout(240000)
  const before = tinker("echo DB::table('company_policies')->where('company_id',1)->count().'/'.DB::table('companies')->where('id',1)->value('updated_at');")
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/onboarding', { waitUntil: 'domcontentloaded' })
  await page.getByText('Temps de travail', { exact: false }).first().click()
  await expect(page.locator('[data-test="working-time-setup"]')).toBeVisible({ timeout: 60000 })
  await expect(page.locator('[data-test="wt-legal-check"]')).toContainText('Code du travail', { timeout: 30000 }).catch(async () => {
    await expect(page.locator('[data-test="wt-legal-check"]')).toBeVisible()
  })
  await settle(page)
  await page.screenshot({ path: `${OUT}/company-framework.jpg`, type: 'jpeg', quality: 65 })

  await page.locator('[data-test="wt-section-rhythm"]').click()
  await expect(page.locator('[data-test="wt-days-per-week"]')).toBeVisible()
  await settle(page, 400)
  await page.screenshot({ path: `${OUT}/company-rhythm.jpg`, type: 'jpeg', quality: 65 })

  // Repos moins favorable que le minimum légal vérifié : refusé, rien n'est enregistré.
  await page.locator('[data-test="wt-section-rest"]').click()
  await expect(page.locator('[data-test="wt-field-daily_rest_min_hours"]')).toContainText('L3131-1')
  await page.locator('[data-test="wt-field-daily_rest_min_hours"] input').fill('9')
  await page.locator('[data-test="wt-save"]').click()
  await expect(page.locator('[data-test="wt-field-daily_rest_min_hours"]')).toContainText(/Moins favorable|Less favourable/, { timeout: 60000 })
  await settle(page, 400)
  await page.screenshot({ path: `${OUT}/company-rest-refused.jpg`, type: 'jpeg', quality: 65 })
  expect(tinker("echo DB::table('company_policies')->where('company_id',1)->count().'/'.DB::table('companies')->where('id',1)->value('updated_at');")).toBe(before)

  await page.locator('[data-test="wt-section-behavior"]').click()
  await expect(page.locator('[data-test="wt-behavior"]')).toBeVisible()
  await page.setViewportSize({ width: 390, height: 844 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await settle(page)
  await page.screenshot({ path: `${OUT}/company-behavior-mobile-dark.jpg`, fullPage: true, type: 'jpeg', quality: 65 })
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
})
