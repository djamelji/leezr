import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { $platformApi } from '@/utils/platformApi'
import { createSurfaceEngine } from '@/core/surface-engine/useSurfaceEngine'

export const useDashboardStore = defineStore('platformDashboard', () => {
  const engine = createSurfaceEngine({
    apiFn: $platformApi,
    scopeStrategy: 'explicit',
  })

  // ── Platform-only extensions ───────────────────────────────────────

  const _presets = ref([])
  const presets = computed(() => _presets.value)

  async function fetchPresets() {
    const data = await $platformApi('/dashboard/layout/presets')

    _presets.value = data.presets
  }

  function updateTile(key, updates) {
    const item = engine._layout.value.find(i => i.key === key)
    if (!item) return

    Object.assign(item, updates)
    engine._dirty.value = true
  }

  function updateWidgetScope(key, scope, companyId = null) {
    const item = engine._layout.value.find(i => i.key === key)
    if (!item) return

    item.scope = scope
    if (scope === 'company' && companyId) {
      item.config = { ...item.config, company_id: companyId }
    }
    else {
      const { company_id: _, ...rest } = item.config || {}

      item.config = rest
    }
    engine._dirty.value = true
  }

  /**
   * Bootstrap starter layout from catalog when dashboard is empty (ADR-201).
   * Platform backend already provides defaultLayout(), this is a safety net.
   * Picks 2 KPIs + 1 chart + 1 list from billing catalog.
   */
  function applyDefaultLayout() {
    const catalog = engine._catalog.value
    const kpis = catalog.filter(w => (w.layout?.default_w || 4) <= 4)
    const charts = catalog.filter(w => (w.layout?.default_w || 4) > 6)
    const lists = catalog.filter(w => {
      const dw = w.layout?.default_w || 4

      return dw > 4 && dw <= 6
    })

    const picks = [
      ...kpis.slice(0, 2),
      ...charts.slice(0, 1),
      ...lists.slice(0, 1),
    ]

    for (const entry of picks) {
      engine.addWidget(entry)
    }
  }

  // Override loadDashboard with bootstrap safety net
  async function loadDashboard() {
    await Promise.all([engine.fetchCatalog(), engine.fetchLayout()])

    // Safety net: empty layout (edge case) → starter from catalog
    if (!engine._layout.value.length && engine._catalog.value.length) {
      applyDefaultLayout()
      await engine.saveLayout()
    }

    await engine.resolveWidgets()
  }

  return {
    ...engine,
    presets,
    fetchPresets,
    updateTile,
    updateWidgetScope,
    loadDashboard,
  }
})
