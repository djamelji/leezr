import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { createSurfaceEngine } from '@/core/surface-engine/useSurfaceEngine'

export const useOperationalHomeStore = defineStore('operationalHome', () => {
  const engine = createSurfaceEngine({
    apiFn: $api,
    scopeStrategy: 'implicit',
  })

  /**
   * Compact Default Layout Builder (ADR-357).
   *
   * Simpler than dashboard's smart builder — stacks all widgets
   * vertically, full-width. Field workers see a focused, linear view.
   */
  function buildCompactDefaultLayout() {
    const catalog = engine._catalog.value
    const layout = []
    let y = 0

    for (const w of catalog) {
      const h = w.layout?.default_h || 4

      layout.push({
        key: w.key,
        x: 0,
        y,
        w: 12,
        h,
        scope: 'company',
        config: w.default_config || {},
      })
      y += h
    }

    engine._layout.value = layout
    engine._dirty.value = true
  }

  async function loadDashboard() {
    await Promise.all([engine.fetchCatalog(), engine.fetchLayout()])

    // Layout resolution cascade (ADR-357):
    //   1. Per-user layout (DB) → already loaded by fetchLayout
    //   2. Role-specific default (DB, user_id=NULL, company_role_id=X) → backend resolveForUser fallback
    //   3. Company default (DB, user_id=NULL, company_role_id=NULL) → backend fallback
    //   4. Compact builder (frontend, fallback when no DB layout at all)
    if (engine._fetchLayoutSucceeded.value && !engine._layout.value.length && engine._catalog.value.length) {
      buildCompactDefaultLayout()
      await engine.saveLayout()
    }

    await engine.resolveWidgets()
  }

  return {
    ...engine,
    loadDashboard,
  }
})
