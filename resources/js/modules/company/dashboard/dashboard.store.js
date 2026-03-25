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
   * Arranges all available company widgets into a pleasant default layout:
   *   Row 0: KPIs (small widgets, default_w ≤ 4, default_h ≤ 2) — up to 4 across
   *   Row 1: Lists (medium, 4 < default_w ≤ 6) — 2 side-by-side
   *   Row 2: Charts (large, default_w > 6) — full-width
   */
  function buildSmartDefaultLayout() {
    const catalog = engine._catalog.value

    const kpis = catalog.filter(w => (w.layout?.default_w || 4) <= 4 && (w.layout?.default_h || 4) <= 2)
    const lists = catalog.filter(w => {
      const dw = w.layout?.default_w || 4
      const dh = w.layout?.default_h || 4

      return dw > 4 && dw <= 6 && dh > 2
    })
    const charts = catalog.filter(w => (w.layout?.default_w || 4) > 6)

    const layout = []
    let y = 0

    // Row 1: KPIs (up to 4 across, h=2)
    const kpiPicks = kpis.slice(0, 4)
    let x = 0

    for (const w of kpiPicks) {
      const dw = w.layout?.default_w || 3

      layout.push({ key: w.key, x, y, w: dw, h: 2, scope: 'company', config: w.default_config || {} })
      x += dw
    }
    if (kpiPicks.length) y += 2

    // Row 2: Lists (2 side-by-side, h=4)
    const listPicks = lists.slice(0, 2)

    x = 0
    for (const w of listPicks) {
      const dw = w.layout?.default_w || 6

      layout.push({ key: w.key, x, y, w: dw, h: 4, scope: 'company', config: w.default_config || {} })
      x += dw
    }
    if (listPicks.length) y += 4

    // Row 3: Charts (full-width, h=4)
    for (const w of charts.slice(0, 1)) {
      layout.push({ key: w.key, x: 0, y, w: 12, h: 4, scope: 'company', config: w.default_config || {} })
      y += 4
    }

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
        await engine.saveLayout()
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
