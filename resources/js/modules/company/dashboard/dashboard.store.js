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
    // ── Composition éditoriale de la PAGE entière (ADR-745) ──
    // Unité de conception = la page. Trois sections adaptatives (Workforce, Logistique,
    // Documents), chacune émise seulement si ≥1 de ses widgets est disponible (permission /
    // module / archétype déjà filtrés serveur). Tailles délibérément variées (strip compact
    // h2 → panneaux composites h3/h4) ; largeurs sommant à 12 → pas de trous.
    //
    // EXCLUS du défaut (restent au catalogue « + Ajouter ») :
    //  - onboarding.setup : rendu hors grille (ADR-383)
    //  - timesheet/leave : déjà portés par la bande « À traiter » (HomePrioritiesSection)
    //  - KPI logistiques unitaires + compliance.pending/overdue/types : REMPLACÉS par les
    //    panneaux composites (shipments.overview, logistics.fleet, documents.detail).
    const EXCLUDED_KEYS = new Set([
      'onboarding.setup',
      'workforce.timesheet_pending', 'workforce.leave_pending',
      'shipments.today', 'shipments.in_transit', 'shipments.late', 'shipments.unassigned', 'drivers.active',
      'deliveries.my_today', 'deliveries.next', 'deliveries.completed_today',
      'compliance.pending', 'compliance.overdue', 'compliance.types',
    ])
    const catalog = engine._catalog.value.filter(w => !EXCLUDED_KEYS.has(w.key))
    const byKey = new Map(catalog.map(w => [w.key, w]))
    const has = k => byKey.has(k)

    const SECTIONS = [
      // Workforce — strip (h2) + hero analyse (h4)
      [
        { key: 'workforce.headcount', w: 3, h: 2 },
        { key: 'workforce.clock_live', w: 3, h: 2 },
        { key: 'planning.shifts', w: 3, h: 2 },
        { key: 'workforce.anomalies', w: 3, h: 2 },
        { key: 'workforce.hours_trend', w: 8, h: 4 },
        { key: 'compliance.rate', w: 4, h: 4 },
      ],
      // Logistique — synthèse expéditions (h3) + flotte & livraisons (h3)
      [
        { key: 'shipments.overview', w: 8, h: 3 },
        { key: 'logistics.fleet', w: 4, h: 3 },
      ],
      // Documents — à régulariser (h3) + conformité par rôle (h3)
      [
        { key: 'documents.detail', w: 6, h: 3 },
        { key: 'compliance.roles', w: 6, h: 3 },
      ],
    ]

    const layout = []
    const placed = new Set()
    const cur = { x: 0, y: 0, rowH: 0 }
    const place = (key, w, h) => {
      const ww = Math.min(w, 12)
      if (cur.x + ww > 12) { cur.x = 0; cur.y += cur.rowH; cur.rowH = 0 }
      layout.push({ key, x: cur.x, y: cur.y, w: ww, h, scope: 'company', config: byKey.get(key)?.default_config || {} })
      placed.add(key)
      cur.x += ww
      cur.rowH = Math.max(cur.rowH, h)
    }
    const newRow = () => { cur.x = 0; cur.y += cur.rowH; cur.rowH = 0 }

    for (const section of SECTIONS) {
      const slots = section.filter(s => has(s.key))
      if (!slots.length) continue
      newRow() // chaque section démarre sur une nouvelle rangée
      for (const s of slots) place(s.key, s.w, s.h)
    }

    // Repli : tout widget autorisé hors sections (ex. plan badge, autres modules) — jamais
    // perdu silencieusement ; placé ensuite à sa taille déclarée.
    newRow()
    for (const w of catalog) {
      if (placed.has(w.key)) continue
      place(w.key, Math.min(w.layout?.default_w || 3, 12), w.layout?.default_h || 2)
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
