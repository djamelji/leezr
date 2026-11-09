import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { $api } from '@/utils/api'
import { useAuthStore } from '@/core/stores/auth'
import { createSurfaceEngine } from '@/core/surface-engine/useSurfaceEngine'

export const useCompanyDashboardStore = defineStore('companyDashboard', () => {
  const engine = createSurfaceEngine({
    apiFn: $api,
    scopeStrategy: 'implicit',
  })

  // ── Company-only extensions ────────────────────────────────────────

  const _suggestions = ref([])
  const suggestions = computed(() => _suggestions.value)

  async function fetchSuggestions() {
    try {
      const data = await $api('/dashboard/suggestions')

      _suggestions.value = data.suggestions ?? []
    }
    catch {
      _suggestions.value = []
    }
  }

  /**
   * Smart Default Layout Builder (ADR-327).
   *
   * Arranges available company widgets into a pleasant default layout:
   *   Row 0: KPIs (small widgets, default_w ≤ 4, default_h ≤ 2) — up to 4 across
   *   Row 1: Lists (medium, 4 < default_w ≤ 6) — 2 side-by-side
   *   Row 2: Banners (wide, default_w > 6) — full-width, actual default_h
   *
   * Widgets without a registered grid component (e.g. onboarding rendered outside
   * the grid per ADR-383) are excluded.
   */
  function buildSmartDefaultLayout() {
    // Exclude widgets rendered outside the grid (no frontend component)
    // onboarding.setup = rendu hors grille. workforce.hours_trend = défaut résiduel
    // (ApexCharts bar/area ne se dimensionne pas de façon fiable dans la cellule de
    // grille ; reste disponible via « + Ajouter un widget »). ADR-744.
    const EXCLUDED_KEYS = new Set(['onboarding.setup', 'workforce.hours_trend'])

    const catalog = engine._catalog.value.filter(w => !EXCLUDED_KEYS.has(w.key))

    // Ordre de priorité métier (ADR-744) — cockpit lisible ; clés inconnues en fin.
    const PRIORITY = [
      'workforce.headcount', 'workforce.clock_live', 'planning.shifts', 'workforce.timesheet_pending',
      'workforce.leave_pending', 'workforce.anomalies', 'compliance.rate',
      'shipments.today', 'shipments.in_transit', 'shipments.late', 'drivers.active',
      'deliveries.my_today', 'deliveries.next', 'deliveries.completed_today',
      'compliance.pending', 'compliance.overdue',
      'workforce.hours_trend', 'compliance.roles', 'compliance.types',
    ]
    const rank = k => { const i = PRIORITY.indexOf(k); return i === -1 ? 999 : i }
    const sorted = [...catalog].sort((a, b) => rank(a.key) - rank(b.key))

    const isKpi = w => (w.layout?.default_w || 4) <= 4 && (w.layout?.default_h || 4) <= 2
    const isBanner = w => (w.layout?.default_w || 4) > 6
    const isList = w => !isKpi(w) && !isBanner(w)

    const kpis = sorted.filter(isKpi).slice(0, 8)                 // jusqu'à 2 rangées de 4
    const mids = sorted.filter(w => isList(w) || isBanner(w)).slice(0, 4) // graphes + listes

    const layout = []
    let y = 0
    let x = 0
    let rowH = 0
    const place = w => {
      const dw = Math.min(w.layout?.default_w || 3, 12)
      const dh = w.layout?.default_h || 2
      if (x + dw > 12) { x = 0; y += rowH; rowH = 0 }
      layout.push({ key: w.key, x, y, w: dw, h: dh, scope: 'company', config: w.default_config || {} })
      x += dw
      rowH = Math.max(rowH, dh)
    }

    for (const w of kpis) place(w)
    // nouvelle rangée pour graphes/listes
    x = 0; y += rowH; rowH = 0
    for (const w of mids) place(w)

    engine._layout.value = layout
    engine._dirty.value = true
  }

  // Override loadDashboard to include suggestions fetch + bootstrap
  async function loadDashboard() {
    await Promise.all([engine.fetchCatalog(), engine.fetchLayout(), fetchSuggestions()])

    // Layout resolution cascade (ADR-327):
    //   1. Per-user layout (DB) → already loaded by fetchLayout
    //   2. Company default from jobdomain (DB, user_id=NULL) → backend resolveForUser fallback
    //   3. Smart default builder (frontend, fallback when no DB layout at all)
    // Only if fetchLayout succeeded — never overwrite on fetch error (ADR-326)
    // Only auto-save if user can manage-structure (PUT /dashboard/layout requires it)
    if (engine._fetchLayoutSucceeded.value && !engine._layout.value.length && engine._catalog.value.length) {
      buildSmartDefaultLayout()

      const auth = useAuthStore()
      if (auth.isAdministrative) {
        try {
          await engine.saveLayout()
        }
        catch {
          // Save failed (422 validation, network, etc.) — layout stays in memory.
          // resolveWidgets must still run to populate widget data.
        }
      }
    }

    await engine.resolveWidgets()
  }

  return {
    ...engine,
    suggestions,
    fetchSuggestions,
    loadDashboard,
  }
})
