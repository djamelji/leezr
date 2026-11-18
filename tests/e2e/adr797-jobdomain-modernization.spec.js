// ADR-797 (L7) — recette Platform : page JobDomain modernisée (identité, modules, presets recommandés,
// diagnostic, historique en lecture seule, aperçu d'une nouvelle société, compatibilité d'une société),
// administration des presets (filtre, détail, nouvelle version, comparaison, publication, archivage, aperçu
// sur une société en lecture seule). Desktop / mobile, clair / sombre.
// Fixtures ISOLÉES : un domaine d'activité et une famille de presets fictifs « e2e_l7_<stamp> », créés par
// l'API et supprimés en fin de recette par leurs identifiants EXACTS. Le domaine « logistique » et la
// société 1 ne sont que LUS.
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { loginPlatform, apiRequest } from './helpers.js'
import { recipeEval, recipePhp } from './recipe.js'

const OUT = 'docs/bmad/adr797'
const settle = (page, ms = 900) => page.waitForTimeout(ms)
const tinker = code => recipeEval(code) // ADR-828 : guarded recipe command, never tinker

test('JobDomain modernisé et administration des presets', async ({ page }) => {
  test.setTimeout(240000)
  const stamp = Date.now().toString().slice(-7)
  const family = `e2e_l7_${stamp}`
  const fixture = { jobdomain: null, presets: [] }

  try {
    await loginPlatform(page)
    const api = async (method, path, data) => {
      const res = await apiRequest(page, { method, path: `/api/platform${path}`, body: data || null })
      expect(res.status < 300, `${method} ${path} → ${res.status}`).toBeTruthy()

      return res.json
    }

    // ── Lecture : domaine « logistique » (configuration historique réelle) ──
    const logi = Number(tinker("echo App\\Core\\Jobdomains\\Jobdomain::where('key','logistique')->value('id');"))
    await page.goto(`/platform/jobdomains/${logi}/setup`)
    await expect(page.locator('[data-test="jd-publication"]')).toBeVisible({ timeout: 20000 })
    await page.locator('[data-test="jd-advanced-diagnostics"]').click() // ADR-821 : diagnostic dans Configuration initiale › avancé
    await expect(page.locator('[data-test="jd-compat-rbac"]')).toContainText('sans équivalent')
    // ADR-829 (D-21) : the draft action exists only while some legacy roles have no preset equivalent. Since
    // `logistique_rbac` v4 is published the count can be 0 — the button must then be absent, not visible.
    const legacyOnly = Number((await page.locator('[data-test="jd-compat-rbac"]').innerText()).match(/(\d+)\s*sans équivalent/)?.[1])
    expect(Number.isInteger(legacyOnly)).toBeTruthy()
    await expect(page.locator('[data-test="jd-draft-rbac"]')).toHaveCount(legacyOnly > 0 ? 1 : 0)
    await page.locator('[data-test="jd-legacy"] button').first().click()
    await expect(page.locator('[data-test="jd-legacy"]')).toContainText('Rôles')
    await page.locator('[data-test="jd-load-plan"]').click()
    await expect(page.locator('[data-test="jd-init"]')).toContainText('écarts techniques', { timeout: 60000 })
    await page.locator('[data-test="jd-report-company"]').click()
    await page.getByRole('option').first().click()
    await expect(page.locator('[data-test="jd-company-report"]')).toContainText('sans poste', { timeout: 30000 })
    await settle(page)
    await page.screenshot({ path: `${OUT}/jobdomain-profile-desktop.jpg`, fullPage: true, type: 'jpeg', quality: 65 })
    await page.emulateMedia({ colorScheme: 'dark' })
    await settle(page)
    await page.screenshot({ path: `${OUT}/jobdomain-profile-dark.jpg`, type: 'jpeg', quality: 65 })
    await page.emulateMedia({ colorScheme: 'light' })

    // ── Fixtures isolées ──
    const jd = await api('POST', '/jobdomains', { key: family, label: `E2E L7 ${stamp}`, description: 'Domaine fictif de recette' })
    fixture.jobdomain = jd.jobdomain.id
    const v1 = await api('POST', '/presets', { kind: 'rbac', family_key: family, label: `E2E RBAC ${stamp}`, content: { roles: [{ key: 'agent', name: 'Agent', bundles: ['members.team_view'] }] }, jobdomain_key: family })
    fixture.presets.push(v1.id)
    await api('POST', `/presets/rbac/${v1.id}/publish`)

    // ── Administration des presets : filtre, détail, nouvelle version, comparaison, publication, aperçu ──
    // ADR-820 / ADR-829 (D-21) : done while the family is still a LIBRARY family — once a JobDomain recommends
    // it, its versions are managed from that JobDomain and the catalog only links there.
    await page.goto('/platform/catalog/presets?kind=rbac')
    await expect(page.locator('[data-test="preset-table"]')).toBeVisible({ timeout: 20000 })
    await page.locator('[data-test="preset-table"] tr', { hasText: family }).first().click()
    await expect(page.locator('[data-test="preset-detail"]')).toContainText(`E2E RBAC ${stamp}`)
    await page.getByRole('button', { name: 'Nouvelle version (brouillon)' }).click()
    await expect(page.locator('[data-test="preset-publish"]')).toBeVisible({ timeout: 15000 })
    fixture.presets.push(Number(tinker(`echo App\\Core\\Presets\\Preset::where('family_key','${family}')->where('version',2)->value('id');`)))
    await page.locator('[data-test="preset-detail"]').getByRole('button', { name: 'Comparer' }).click()
    await expect(page.locator('[data-test="preset-diff"]')).toContainText('v1 → v2')
    await page.locator('[data-test="preset-publish"]').click()
    await expect(page.locator('[data-test="preset-detail"]')).toContainText('Publiée', { timeout: 15000 })
    await page.locator('[data-test="preset-detail"] input[type="number"]').fill('1')
    await page.getByRole('button', { name: 'Prévisualiser' }).click()
    await expect(page.locator('[data-test="preset-preview"]')).toContainText('Rien n’est appliqué', { timeout: 60000 })
    await settle(page)
    await page.screenshot({ path: `${OUT}/presets-admin-detail.jpg`, type: 'jpeg', quality: 65 })

    // ── Domaine fictif : identité + association d'un preset (version épinglée) ──
    await page.goto(`/platform/jobdomains/${fixture.jobdomain}`)
    await expect(page.locator('[data-test="jd-identity"]')).toBeVisible({ timeout: 20000 })
    await page.locator('[data-test="jd-sector"] input').fill('Recette fictive')
    // ADR-821 (D-21) : one save for the General section ; a published family is associated from
    // Configuration initiale › avancé › family › « use a shared preset » (no manual version choice any more).
    await page.locator('[data-test="jd-save-general"]').click()
    await expect(page.getByText('Enregistré.').first()).toBeVisible()
    await page.locator('[data-test="jd-tabbtn-setup"]').click()
    await page.locator('[data-test="jd-advanced-families"]').click()
    await page.locator('[data-test="jd-family-rbac"]').click()
    await page.locator('[data-test="jd-share-rbac"] .v-field').first().click()
    await page.getByRole('option', { name: new RegExp(`E2E RBAC ${stamp}`) }).click()
    await page.locator('[data-test="jd-share-rbac"]').getByRole('button', { name: 'Associer' }).click()
    await expect(page.locator('[data-test="jd-tab-rbac"]')).toContainText('En vigueur', { timeout: 15000 })
    await settle(page)
    await page.screenshot({ path: `${OUT}/jobdomain-presets-association.jpg`, type: 'jpeg', quality: 65 })

    // Une nouvelle version ne réapplique rien et ne change pas la version épinglée du domaine.
    let rec = (await api('GET', `/jobdomains/${family}/preset-recommendations`)).recommendations[0]
    expect(rec.effective_version).toBe(2) // non épinglée → dernière publiée (recommandation, pas application)
    await api('PUT', `/jobdomains/${family}/preset-recommendations`, { links: [{ kind: 'rbac', family_key: family, pinned_version: 1 }] })
    rec = (await api('GET', `/jobdomains/${family}/preset-recommendations`)).recommendations[0]
    expect([rec.effective_version, rec.newer_published]).toEqual([1, 2]) // épinglée : la v2 est signalée, jamais imposée
    expect(Number(tinker(`echo App\\Core\\Presets\\PresetApplication::where('family_key','${family}')->count();`))).toBe(0)

    // ── Mobile ──
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(`/platform/jobdomains/${fixture.jobdomain}`)
    await expect(page.locator('[data-test="jd-identity"]')).toBeVisible({ timeout: 20000 })
    await settle(page)
    await page.screenshot({ path: `${OUT}/jobdomain-profile-mobile.jpg`, fullPage: true, type: 'jpeg', quality: 65 })
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow).toBeLessThanOrEqual(1)
  }
  finally {
    // Nettoyage EXACT des fixtures de cette recette (jamais par préfixe).
    if (fixture.presets.length || fixture.jobdomain) {
      tinker(`App\\Core\\Presets\\JobdomainPresetLink::where('jobdomain_key','${family}')->delete(); App\\Core\\Presets\\Preset::whereIn('id',[${fixture.presets.filter(Boolean).join(',') || 0}])->where('family_key','${family}')->delete(); App\\Core\\Jobdomains\\Jobdomain::where('id',${fixture.jobdomain || 0})->where('key','${family}')->delete(); echo 'ok';`)
    }
  }
})
