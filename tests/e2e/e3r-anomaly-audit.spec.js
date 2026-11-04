// @ts-check
// E3-R / R1 — détecteur d'anomalies responsive MESURÉES (pas qu'un screenshot) sur l'app réelle.
// Mesure le débordement horizontal, les onglets tronqués, les zones tactiles, à plusieurs largeurs,
// multi-contexte (Company, portail salarié, module non-Workforce). Écrit un rapport JSON + captures.
import { mkdirSync, writeFileSync } from 'fs'
import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers'

const OUT = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad/e3r-audit'
mkdirSync(OUT, { recursive: true })

const WIDTHS = [320, 375, 390, 430, 768, 1024, 1366, 1920]
const PAGES = [
  { key: 'dashboard', url: '/dashboard', ready: 'Bienvenue' },
  { key: 'annuaire', url: '/company/workforce/employees', ready: 'Collaborateurs' },
  { key: 'fiche', url: '/company/workforce/employees/1', ready: 'Djamel' },
  { key: 'time', url: '/company/workforce/time', ready: null },
  { key: 'leave', url: '/company/workforce/leave', ready: null },
  { key: 'payroll', url: '/company/workforce/payroll', ready: null },
  { key: 'organization', url: '/company/workforce/organization/departments', ready: null },
  { key: 'portal', url: '/company/workforce/me', ready: null },
  { key: 'members', url: '/company/members', ready: null }, // module Core non-Workforce
]

async function measure(page) {
  return await page.evaluate(() => {
    const de = document.documentElement
    const overflow = Math.max(0, de.scrollWidth - window.innerWidth)
    // Éléments qui débordent réellement de la fenêtre (rect.right > innerWidth + 1).
    const offenders = []
    const seen = new Set()
    document.querySelectorAll('*').forEach(el => {
      const r = el.getBoundingClientRect()
      if (r.width > 0 && r.right > window.innerWidth + 1 && r.left < window.innerWidth) {
        const tag = el.tagName.toLowerCase()
        const cls = (el.className && typeof el.className === 'string') ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''
        const sig = tag + cls
        if (!seen.has(sig) && offenders.length < 8) { seen.add(sig); offenders.push({ sig, right: Math.round(r.right) }) }
      }
    })
    // Onglets tronqués : VTabs avec flèches de défilement actives (contenu > conteneur).
    const tabsEls = [...document.querySelectorAll('.v-tabs')]
    const truncatedTabs = tabsEls.some(t => {
      const win = t.querySelector('.v-slide-group__content')

      return win && win.scrollWidth > win.clientWidth + 2
    })
    // Petites cibles tactiles (<40px) parmi boutons/icônes visibles (échantillon).
    let smallTargets = 0
    document.querySelectorAll('button, .v-btn, a.v-btn').forEach(el => {
      const r = el.getBoundingClientRect()
      if (r.width > 0 && r.height > 0 && (r.height < 36 || r.width < 36)) smallTargets++
    })

    return { overflow, offenders, truncatedTabs, smallTargets }
  })
}

test('audit anomalies responsive (Company + portail + non-Workforce)', async ({ page }) => {
  test.setTimeout(600000)
  await loginCompany(page, 'owner@leezr.test')
  const report = []

  for (const p of PAGES) {
    for (const w of WIDTHS) {
      await page.setViewportSize({ width: w, height: 900 })
      await page.goto(p.url, { waitUntil: 'domcontentloaded' })
      if (p.ready) await page.getByText(p.ready, { exact: false }).first().waitFor({ state: 'visible', timeout: 12000 }).catch(() => {})
      else await page.waitForTimeout(2500)
      await page.waitForTimeout(600)
      const m = await measure(page)
      report.push({ page: p.key, url: p.url, width: w, ...m })
      // Capture mobile (390) et tablette (768) pour l'inventaire visuel.
      if (w === 390 || w === 768)
        await page.screenshot({ path: `${OUT}/${p.key}-${w}.png`, fullPage: false })
    }
  }

  writeFileSync(`${OUT}/anomalies.json`, JSON.stringify(report, null, 2))

  // Synthèse lisible dans la sortie du test.
  const overflows = report.filter(r => r.overflow > 2)
  const tabs = report.filter(r => r.truncatedTabs)
  console.log('\n=== ANOMALIES RESPONSIVE (R1) ===')
  console.log(`Débordements horizontaux (>2px): ${overflows.length} cas`)
  for (const o of overflows)
    console.log(`  ${o.page} @${o.width}px → +${o.overflow}px [${o.offenders.map(x => x.sig).join(', ')}]`)
  console.log(`Onglets tronqués: ${[...new Set(tabs.map(t => t.page))].join(', ') || 'aucun'}`)
  console.log(`Rapport complet: ${OUT}/anomalies.json`)

  // Le test ne DOIT PAS échouer (c'est un audit) — on consigne seulement.
  expect(report.length).toBeGreaterThan(0)
})
