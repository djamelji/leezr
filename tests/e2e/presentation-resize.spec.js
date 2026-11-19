// ADR-748b — Recette de RESIZE DYNAMIQUE du Presentation Engine.
// Prouve que le graphe du widget commercial ne disparaît JAMAIS pendant/après un
// redimensionnement libre du conteneur, sur la trajectoire exigée + cycles répétés
// réduction/agrandissement. Mesure la stabilité PENDANT le resize (échantillons
// intermédiaires), pas seulement à l'arrêt.
import { test, expect } from '@playwright/test'
import { loginPlatform } from './helpers.js'

const MODES = ['micro', 'compact', 'standard', 'wide', 'expanded']

// Trajectoire officielle (tailles de tuile en px) imposée par la spec ADR-748b.
const TRAJECTORY = [
  { w: 1200, h: 600 }, { w: 700, h: 500 }, { w: 350, h: 180 },
  { w: 1000, h: 220 }, { w: 500, h: 350 }, { w: 1200, h: 600 },
]
// Cycles répétés réduction → agrandissement.
const CYCLES = [
  { w: 1200, h: 600 }, { w: 300, h: 170 }, { w: 1200, h: 600 }, { w: 320, h: 200 },
  { w: 1100, h: 520 }, { w: 280, h: 160 }, { w: 1000, h: 300 }, { w: 1200, h: 600 },
]

async function inspect(box) {
  return box.evaluate(el => {
    const q = s => el.querySelector(s)
    const rect = s => { const e = q(s); return e ? e.getBoundingClientRect() : null }
    const root = rect('.widget-root')
    const host = rect('.cw-chart-host')
    const svg = rect('.apexcharts-svg')
    const spark = rect('.apexcharts-canvas') // area/sparkline partagent le canvas
    const headers = el.querySelectorAll('.cw-microhead, .cw-head')
    // Dépassement : le contenu déborde-t-il de la tuile ?
    const overflowX = el.scrollWidth > el.clientWidth + 2
    const overflowY = el.scrollHeight > el.clientHeight + 2
    // Chevauchement en-tête/graphe : le haut du graphe doit être sous le bas de l'en-tête.
    let overlap = false
    const headerEls = [...headers]
    if (host && headerEls.length) {
      const lastHeaderBottom = Math.max(...headerEls.map(h => h.getBoundingClientRect().bottom))
      overlap = host.top < lastHeaderBottom - 2
    }
    const loading = !!q('.v-progress-circular')
    const modeClass = (el.querySelector('.widget-root')?.className.match(/mode-(\w+)/) || [])[1] || null
    // Le MRR (donnée clé) doit rester affiché quelque part.
    const txt = el.textContent || ''
    const hasMoney = /€|\d/.test(txt)
    return {
      rootW: root ? Math.round(root.width) : -1,
      rootH: root ? Math.round(root.height) : -1,
      hostH: host ? Math.round(host.height) : -1,
      canvas: q('.apexcharts-canvas') ? 1 : 0,
      svgH: svg ? Math.round(svg.height) : -1,
      headerCount: headerEls.length,
      overflowX, overflowY, overlap, loading, modeClass, hasMoney,
    }
  })
}

test('ADR-748b — resize dynamique : le graphe ne disparaît jamais', async ({ page }) => {
  const jsErrors = []
  page.on('pageerror', e => jsErrors.push(String(e)))

  await loginPlatform(page)
  await page.setViewportSize({ width: 1600, height: 1000 })
  await page.goto('/platform/presentation-lab?live=1')
  await page.waitForTimeout(1500)
  // ADR-831 (D-21 bis) — the built product redirects lab routes away (ADR-818) : run only against the Vite dev server.
  test.skip(!page.url().includes('/platform/presentation-lab'), 'ADR-818 — laboratoire dev-only (route `devOnly`) : absent du produit construit, uniquement avec le serveur Vite (pnpm dev)')
  await page.waitForFunction(() => typeof window.__setLive === 'function')
  const box = page.locator('.lab-box[data-live="1"]')
  // Rendu initial grande taille.
  await page.evaluate(() => window.__setLive(1200, 600))
  await expect(box.locator('.apexcharts-canvas')).toBeVisible({ timeout: 8000 })
  // Laisser le rendu initial se stabiliser complètement (settle resizeKey + 1er draw)
  // avant d'échantillonner « pendant resize » — sinon la 1re mesure court-circuite la
  // création initiale du canvas (faux négatif sans rapport avec un vrai resize).
  await page.waitForTimeout(700)

  const steps = [...TRAJECTORY, ...CYCLES]
  const failures = []
  let duringLost = 0, duringSamples = 0

  for (let i = 0; i < steps.length; i++) {
    const s = steps[i]
    await page.evaluate(([w, h]) => window.__setLive(w, h), [s.w, s.h])

    // Échantillon PENDANT la transition (avant stabilisation) — le graphe doit rester présent.
    await page.waitForTimeout(90)
    const mid = await inspect(box)
    duringSamples++
    if (mid.canvas === 0) duringLost++

    // Après stabilisation (settle 220ms + marge).
    await page.waitForTimeout(500)
    const after = await inspect(box)
    const label = `${s.w}x${s.h}`

    const problems = []
    if (after.canvas === 0) problems.push('canvas absent')
    if (after.svgH < 20) problems.push(`svg trop petit (${after.svgH})`)
    if (after.rootW <= 0 || after.rootH <= 0) problems.push('dims non positives')
    if (!MODES.includes(after.modeClass)) problems.push(`mode invalide (${after.modeClass})`)
    if (after.headerCount !== 1) problems.push(`en-têtes=${after.headerCount} (attendu 1)`)
    if (after.overflowX) problems.push('overflow-x')
    if (after.overflowY) problems.push('overflow-y')
    if (after.overlap) problems.push('chevauchement en-tête/graphe')
    if (after.loading) problems.push('loading permanent')
    if (!after.hasMoney) problems.push('donnée MRR absente')
    if (problems.length) failures.push(`[${label}] ${problems.join(', ')}`)
    console.log(`${label.padEnd(9)} mid.canvas=${mid.canvas} → mode=${after.modeClass} host=${after.hostH} svg=${after.svgH} hdr=${after.headerCount} ovf=${after.overflowX?'X':''}${after.overflowY?'Y':''} ${problems.length?'❌ '+problems.join(','):'✓'}`)
  }

  // Contrôles fonctionnels après toute la trajectoire (grande taille).
  await page.evaluate(() => window.__setLive(1200, 600))
  await page.waitForTimeout(500)
  const periodBtn = box.locator('.cw-periods .v-btn, .cw-select').first()
  await expect(periodBtn).toBeVisible()

  console.log(`\nPENDANT resize : perdu ${duringLost}/${duringSamples} échantillons`)
  console.log(`APRÈS resize : ${failures.length} étape(s) en échec sur ${steps.length}`)
  console.log(`Erreurs JS : ${jsErrors.length}`)

  expect(jsErrors, `Erreurs JS: ${jsErrors.join(' | ')}`).toHaveLength(0)
  expect(duringLost, 'graphe disparu PENDANT un resize').toBe(0)
  expect(failures, `Échecs APRÈS resize:\n${failures.join('\n')}`).toHaveLength(0)
})
