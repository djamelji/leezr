/**
 * Leezr 5.0 (L1, ADR-855) — presentation-mode PROFILES per widget family.
 *
 * Same measurement as the Presentation Engine (ResizeObserver on the real container, ADR-748/750) and the same
 * mode vocabulary ; only the thresholds differ by family. The single chart profile (`resolvePresentationMode`,
 * micro under 210 px of height) left every KPI tile (2 grid rows = 160 px) in « micro » : the label and the
 * context never showed. This is not a second engine : `chart` delegates to the existing function.
 */
import { resolvePresentationMode } from './presentationMode.js'

/** KPI tiles : readable from 2 × 2 grid cells ; never « micro » because of height alone. */
export function resolveKpiMode(w, h) {
  const pw = Number(w) || 0
  const ph = Number(h) || 0
  if (!pw) return 'standard'
  if (pw < 170 || ph < 90) return 'micro'
  if (pw < 230) return 'compact'
  if (pw >= 520) return ph >= 230 ? 'expanded' : 'wide'

  return 'standard'
}

/** Lists / queues : the number of rows follows the height (56 px a row) ; columns follow the width. */
export function resolveListMode(w, h) {
  const pw = Number(w) || 0
  const ph = Number(h) || 0
  if (!pw) return 'standard'
  if (ph < 150 || pw < 240) return 'micro'
  if (pw < 380) return 'compact'
  if (pw >= 760) return 'wide'

  return 'standard'
}

export function listRows(h, rowPx = 56, chrome = 72) {
  return Math.max(1, Math.floor(((Number(h) || 0) - chrome) / rowPx))
}

export const FAMILY_RESOLVERS = {
  chart: resolvePresentationMode,
  kpi: resolveKpiMode,
  list: resolveListMode,
}

export function resolveFamilyMode(family, w, h) {
  return (FAMILY_RESOLVERS[family] || resolvePresentationMode)(w, h)
}
