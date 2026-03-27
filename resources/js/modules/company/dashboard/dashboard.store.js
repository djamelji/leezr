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
    const EXCLUDED_KEYS = new Set(['onboarding.setup'])

    const catalog = engine._catalog.value.filter(w => !EXCLUDED_KEYS.has(w.key))

    const kpis = catalog.filter(w => (w.layout?.default_w || 4) <= 4 && (w.layout?.default_h || 4) <= 2)
    const lists = catalog.filter(w => {
      const dw = w.layout?.default_w || 4
      const dh = w.layout?.default_h || 4

      return dw > 4 && dw <= 6 && dh > 2
    })
    const banners = catalog.filter(w => (w.layout?.default_w || 4) > 6)

    const layout = []
    let y = 0

    // Row 1: KPIs (up to 4 across)
    const kpiPicks = kpis.slice(0, 4)
    let x = 0

    for (const w of kpiPicks) {
      const dw = w.layout?.default_w || 3
      const dh = w.layout?.default_h || 2

      layout.push({ key: w.key, x, y, w: dw, h: dh, scope: 'company', config: w.default_config || {} })
      x += dw
    }
    if (kpiPicks.length) y += (kpiPicks[0].layout?.default_h || 2)

    // Row 2: Lists (2 side-by-side)
    const listPicks = lists.slice(0, 2)

    x = 0
    for (const w of listPicks) {
      const dw = w.layout?.default_w || 6
      const dh = w.layout?.default_h || 4

      layout.push({ key: w.key, x, y, w: dw, h: dh, scope: 'company', config: w.default_config || {} })
      x += dw
    }
    if (listPicks.length) y += (listPicks[0].layout?.default_h || 4)

    // Row 3: Banners (full-width, respect widget's actual default_h)
    for (const w of banners.slice(0, 1)) {
      const dh = w.layout?.default_h || 2

      layout.push({ key: w.key, x: 0, y, w: w.layout?.default_w || 12, h: dh, scope: 'company', config: w.default_config || {} })
      y += dh
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
