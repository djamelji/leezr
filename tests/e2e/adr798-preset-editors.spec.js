// ADR-798 — recette Platform : création et édition SPÉCIALISÉE de presets (RBAC, Exigences, Planning) sans
// JSON, publication, archivage refusé quand une recommandation casserait, exigences de l'entreprise d'une
// société (lecture), parité création historique / versionnée depuis l'historique (simulation annulée).
// Desktop / mobile, clair / sombre. Fixtures ISOLÉES (« e2e_798_<stamp> ») supprimées par identifiants
// EXACTS ; le domaine « logistique » et les sociétés réelles ne sont que LUS.
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { loginPlatform, apiRequest } from './helpers.js'

const OUT = 'docs/bmad/adr798'
const settle = (page, ms = 900) => page.waitForTimeout(ms)
const tinker = code => execFileSync('php', ['artisan', 'tinker', '--execute', code], { encoding: 'utf8' }).trim().split('\n').pop()

test('éditeurs de presets, cycle de vie, exigences entreprise, parité', async ({ page }) => {
  test.setTimeout(300000)
  const stamp = Date.now().toString().slice(-7)
  const family = `e2e_798_${stamp}`
  const fixture = { jobdomain: null }
  const api = async (method, path, body) => {
    const res = await apiRequest(page, { method, path: `/api/platform${path}`, body: body || null })
    expect(res.status < 300, `${method} ${path} → ${res.status}`).toBeTruthy()

    return res.json
  }

  try {
    await loginPlatform(page)
    const jd = await api('POST', '/jobdomains', { key: family, label: `E2E 798 ${stamp}`, description: 'Domaine fictif de recette' })
    fixture.jobdomain = jd.jobdomain.id

    // ── Création d'un preset RBAC par l'éditeur (aucun JSON) ──
    await page.goto('/platform/catalog/presets')
    await page.locator('[data-test="preset-new"]').click()
    await page.locator('[data-test="preset-new-rbac"]').click()
    await expect(page.locator('[data-test="preset-editor"]')).toBeVisible({ timeout: 20000 })
    await page.locator('[data-test="preset-editor-label"] input').fill(`E2E RBAC ${stamp}`)
    await page.getByLabel('Identifiant de la famille').fill(`${family}_rbac`)
    await page.locator('[data-test="preset-add-role"]').click()
    const role = page.locator('[data-test="preset-role-0"]')
    await role.getByLabel('Libellé du rôle').fill('Agent de quai')
    await role.getByLabel(/Lots de permissions/).click()
    await page.getByRole('option').first().click()
    await page.keyboard.press('Escape')
    await settle(page, 400)
    await page.screenshot({ path: `${OUT}/editor-rbac.jpg`, type: 'jpeg', quality: 65 })
    await page.locator('[data-test="preset-editor-save"]').click()
    await expect(page.locator('[data-test="preset-detail"]')).toContainText(`E2E RBAC ${stamp}`, { timeout: 60000 })

    // Modification du brouillon, puis publication.
    await page.locator('[data-test="preset-edit"]').click()
    await page.locator('[data-test="preset-add-role"]').click()
    await page.locator('[data-test="preset-role-1"]').getByLabel('Libellé du rôle').fill('Chef de quai')
    await page.locator('[data-test="preset-role-1"]').getByLabel('Administratif').check()
    await expect(page.locator('[data-test="preset-role-1"]')).toContainText('Rôle sensible')
    await page.locator('[data-test="preset-role-1"]').getByLabel(/Lots de permissions/).click()
    await page.getByRole('option').first().click()
    await page.keyboard.press('Escape')
    await page.locator('[data-test="preset-editor-save"]').click()
    await expect(page.locator('[data-test="preset-detail"]')).toContainText('Chef de quai', { timeout: 60000 })
    await page.locator('[data-test="preset-publish"]').click()
    await expect(page.locator('[data-test="preset-detail"]')).toContainText('Publiée', { timeout: 60000 })

    // ── Preset d'exigences : entreprise + collaborateur ──
    await page.locator('[data-test="preset-detail"] button[aria-label="Fermer"]').click()
    await page.locator('[data-test="preset-new"]').click()
    await page.locator('[data-test="preset-new-requirements"]').click()
    await page.locator('[data-test="preset-editor-label"] input').fill(`E2E Exigences ${stamp}`)
    await page.getByLabel('Identifiant de la famille').fill(`${family}_req`)
    for (const [i, subject, target] of [[0, 'Entreprise', /K-bis|Kbis/i], [1, 'Collaborateur', /Identity|identité/i]]) {
      await page.locator('[data-test="preset-add-item"]').click()
      const it = page.locator(`[data-test="preset-item-${i}"]`)
      const selects = it.locator('.v-select')
      await selects.nth(0).click() // Demandé à
      await page.getByRole('option', { name: subject, exact: true }).click()
      await it.locator('.v-autocomplete').first().click() // Élément du catalogue
      await page.getByRole('option', { name: target }).first().click()
      await selects.nth(2).click() // Niveau
      await page.getByRole('option', { name: 'Obligatoire configurable' }).click()
    }
    await settle(page, 400)
    await page.screenshot({ path: `${OUT}/editor-requirements.jpg`, type: 'jpeg', quality: 65 })
    await page.locator('[data-test="preset-editor-save"]').click()
    await expect(page.locator('[data-test="preset-detail"]')).toContainText('company>document:kbis', { timeout: 60000 })
    await page.locator('[data-test="preset-publish"]').click()
    await expect(page.locator('[data-test="preset-detail"]')).toContainText('Publiée', { timeout: 60000 })

    // ── Recommandation puis archivage refusé ──
    await api('PUT', `/jobdomains/${family}/preset-recommendations`, { links: [{ kind: 'rbac', family_key: `${family}_rbac` }, { kind: 'requirements', family_key: `${family}_req` }] })
    await page.locator('[data-test="preset-archive"]').click()
    await expect(page.locator('[data-test="preset-archive-blocked"]')).toContainText(family, { timeout: 60000 })
    await settle(page, 400)
    await page.screenshot({ path: `${OUT}/archive-blocked.jpg`, type: 'jpeg', quality: 65 })

    // ── Domaine « logistique » (lecture) : exigences de l'entreprise d'une société + parité depuis l'historique ──
    const logi = Number(tinker("echo App\\Core\\Jobdomains\\Jobdomain::where('key','logistique')->value('id');"))
    await page.goto(`/platform/jobdomains/${logi}/publication`)
    await expect(page.locator('[data-test="jd-publication"]')).toBeVisible({ timeout: 30000 })
    await page.locator('[data-test="jd-advanced"] .v-expansion-panel-title').click() // ADR-820 : diagnostic dans Publication › avancé
    await expect(page.locator('[data-test="jd-company-report"]')).toBeVisible({ timeout: 30000 })
    await page.locator('[data-test="jd-report-company"]').click()
    await page.getByRole('option', { name: /\(#1\)/ }).click()
    await expect(page.locator('[data-test="jd-company-requirements"]')).toBeVisible({ timeout: 60000 })
    await page.locator('[data-test="jd-company-requirements"]').scrollIntoViewIfNeeded()
    await settle(page)
    await page.screenshot({ path: `${OUT}/company-requirements.jpg`, type: 'jpeg', quality: 65 })
    await page.locator('[data-test="jd-from-legacy"] input').check()
    await page.locator('[data-test="jd-load-plan"]').click()
    await expect(page.locator('[data-test="jd-init"]')).toContainText('différences volontaires', { timeout: 90000 })
    await page.locator('[data-test="jd-init"]').scrollIntoViewIfNeeded()
    await settle(page)
    await page.screenshot({ path: `${OUT}/creation-parity.jpg`, type: 'jpeg', quality: 65 })
    expect(Number(tinker("echo App\\Core\\Models\\Company::where('name','like','Parity %')->count();"))).toBe(0) // simulation annulée

    // ── Sombre et mobile ──
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.goto('/platform/catalog/presets?kind=requirements')
    await expect(page.locator('[data-test="preset-table"]')).toBeVisible({ timeout: 30000 })
    await settle(page)
    await page.screenshot({ path: `${OUT}/presets-dark.jpg`, type: 'jpeg', quality: 65 })
    await page.emulateMedia({ colorScheme: 'light' })
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/platform/catalog/presets')
    await expect(page.locator('[data-test="preset-table"]')).toBeVisible({ timeout: 30000 })
    await page.locator('[data-test="preset-table"] tr', { hasText: `${family}_req` }).first().click()
    await page.locator('[data-test="preset-detail"]').getByRole('button', { name: 'Nouvelle version (brouillon)' }).click()
    await expect(page.locator('[data-test="preset-edit"]')).toBeVisible({ timeout: 60000 })
    await page.locator('[data-test="preset-edit"]').click()
    await expect(page.locator('[data-test="preset-editor"]')).toBeVisible()
    await settle(page)
    await page.screenshot({ path: `${OUT}/editor-mobile.jpg`, fullPage: true, type: 'jpeg', quality: 65 })
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow).toBeLessThanOrEqual(1)
  }
  finally {
    // Nettoyage EXACT : familles et domaine fictifs de cette recette uniquement.
    tinker(`App\\Core\\Presets\\JobdomainPresetLink::where('jobdomain_key','${family}')->delete(); $ids=App\\Core\\Presets\\Preset::whereIn('family_key',['${family}_rbac','${family}_req'])->pluck('id'); App\\Core\\Requirements\\Requirement::whereNull('company_id')->where('source','preset')->whereIn('source_ref',['${family}_rbac','${family}_req'])->delete(); App\\Core\\Presets\\Preset::whereIn('id',$ids)->delete(); App\\Core\\Jobdomains\\Jobdomain::where('id',${fixture.jobdomain || 0})->where('key','${family}')->delete(); echo 'ok';`)
  }
})
