import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { $api } from '@/utils/api'
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
   * Bootstrap starter layout from catalog when dashboard is empty (ADR-201).
   * Picks 3 KPIs + 1 list/chart from available catalog.
   * Idempotent: only called when engine._layout.value is empty.
   */
  function applyDefaultLayout() {
    const catalog = engine._catalog.value
    const kpis = catalog.filter(w => (w.layout?.default_w || 4) <= 4)
    const lists = catalog.filter(w => {
      const dw = w.layout?.default_w || 4

      return dw > 4 && dw <= 6
    })
    const charts = catalog.filter(w => (w.layout?.default_w || 4) > 6)

    const picks = [
      ...kpis.slice(0, 3),
      ...(lists[0] ? [lists[0]] : charts.slice(0, 1)),
    ]

    for (const entry of picks) {
      engine.addWidget(entry)
    }
  }

  // Override loadDashboard to include suggestions fetch + bootstrap
  // clientCatalog: client-only entries (e.g. compliance) injected BEFORE bootstrap check
  async function loadDashboard(clientCatalog = []) {
    await Promise.all([engine.fetchCatalog(), engine.fetchLayout(), fetchSuggestions()])

    // Inject client-only catalog entries so bootstrap can pick from them
    if (clientCatalog.length) {
      engine.injectCatalogEntries(clientCatalog)
    }

    // Bootstrap: empty layout at first login → starter from catalog
    if (!engine._layout.value.length && engine._catalog.value.length) {
      applyDefaultLayout()
      await engine.saveLayout()
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
