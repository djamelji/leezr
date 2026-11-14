// @ts-check
// ADR-782 (UX6) — « Modules » : entrée principale du menu (desktop et mobile), absente des Réglages,
// visible seulement avec `modules.manage` ; la page catalogue s'ouvre sans erreur ni débordement.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { apiRequest, loginCompany } from './helpers.js'

const OUT = 'docs/bmad/ux6-782'
const watch = page => { const bad = []; page.on('pageerror', e => bad.push(String(e.message).slice(0, 160))); page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 500) bad.push(`${r.status()} ${r.url()}`) }); return bad }

test('Modules : entrée principale pour le propriétaire, hors Réglages (desktop, mobile)', async ({ page }) => {
  const bad = watch(page)
  await loginCompany(page, 'owner@leezr.test')
  const nav = (await apiRequest(page, { path: '/api/nav' })).json
  const keys = (nav.groups || []).filter(g => g.placement === 'nav').map(g => g.key)
  expect(keys.indexOf('modules'), 'espace Modules avant Réglages').toBeGreaterThan(-1)
  expect(keys.indexOf('modules')).toBeLessThan(keys.indexOf('settings'))
  const hub = (nav.groups.find(g => g.key === 'settings')?.items?.[0]?.children || []).map(c => c.key)
  expect(hub, 'Modules absent du hub Réglages').not.toContain('modules')

  await page.goto('/dashboard', { waitUntil: 'networkidle' })
  const link = page.locator('.layout-vertical-nav a[href="/company/modules"]').first()
  await expect(link).toBeVisible()
  await link.click()
  await page.waitForURL(/\/company\/modules$/)
  await expect(page.locator('[data-test="modules-summary"]')).toBeVisible()
  await page.screenshot({ path: `${OUT}/after-modules-desktop-light.jpg`, type: 'jpeg', quality: 60 })

  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/company/modules', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  expect(await page.evaluate(() => document.documentElement.scrollWidth), 'mobile sans débordement').toBeLessThanOrEqual(390)
  await page.screenshot({ path: `${OUT}/after-modules-mobile-light.jpg`, type: 'jpeg', quality: 60, fullPage: true })
  // Menu mobile : l'entrée est dans le tiroir de navigation.
  await page.locator('.layout-navbar .nav-item-toggle, .layout-navbar [class*="menu"] button, .layout-navbar button').first().click().catch(() => {})
  await page.waitForTimeout(600)
  await expect(page.locator('.layout-vertical-nav a[href="/company/modules"]').first()).toBeVisible()
  await page.screenshot({ path: `${OUT}/after-modules-mobile-menu.jpg`, type: 'jpeg', quality: 60 })
  expect(bad).toEqual([])
})

test('Modules : invisible et refusé sans modules.manage', async ({ page }) => {
  const result = {}
  for (const who of ['admin', 'rh-nopay', 'manager', 'salarie']) {
    await page.context().clearCookies()
    await loginCompany(page, `${who}@leezr.test`)
    const nav = (await apiRequest(page, { path: '/api/nav' })).json
    const has = (nav.groups || []).some(g => g.key === 'modules')
    await page.goto('/company/modules', { waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    result[who] = { navEntry: has, landed: new URL(page.url()).pathname }
  }
  fs.writeFileSync(`${OUT}/result-modules-access.json`, JSON.stringify(result, null, 1))
  for (const [who, r] of Object.entries(result)) {
    expect(r.navEntry, `${who} : pas d'entrée Modules`).toBe(false)
    expect(r.landed, `${who} : page refusée`).not.toBe('/company/modules')
  }
})
