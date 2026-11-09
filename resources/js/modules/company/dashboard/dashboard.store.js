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
    // onboarding.setup = rendu hors grille (ADR-383).
    // timesheet_pending / leave_pending = déjà portés par la bande « À traiter »
    // (HomePrioritiesSection) → exclus du défaut pour éviter la duplication ; restent
    // au catalogue (« + Ajouter un widget »). ADR-745.
    const EXCLUDED_KEYS = new Set(['onboarding.setup', 'workforce.timesheet_pending', 'workforce.leave_pending'])
    const catalog = engine._catalog.value.filter(w => !EXCLUDED_KEYS.has(w.key))
    const byKey = new Map(catalog.map(w => [w.key, w]))

    // ── Composition éditoriale Workforce (ADR-745) ──
    // Unité de conception = la PAGE, pas le widget. Tailles délibérément variées pour
    // créer un rythme (strip compact → hero analytique → exploitation), pas une grille
    // uniforme. Chaque slot tombe gracieusement si le widget est absent (permission /
    // module / archétype).
    //   Rangée 1 — KPI strip (h2) : 4 stats compactes (sans doublon de « À traiter »)
    //   Rangée 2 — Analyse (h4)    : grand graphe heures + conformité (jauge enrichie)
    // timesheet_pending / leave_pending sont volontairement HORS défaut : la bande
    // fixe « À traiter » (HomePrioritiesSection) les porte déjà comme actions → pas de
    // duplication visuelle. Ils restent au catalogue (« + Ajouter un widget »).
    const EDITORIAL = [
      { key: 'workforce.headcount', w: 3, h: 2 },
      { key: 'workforce.clock_live', w: 3, h: 2 },
      { key: 'planning.shifts', w: 3, h: 2 },
      { key: 'workforce.anomalies', w: 3, h: 2 },
      { key: 'workforce.hours_trend', w: 8, h: 4 },
      { key: 'compliance.rate', w: 4, h: 4 },
    ]

    // Slots présents dans le catalogue (autorisation/module/archétype déjà filtrés serveur).
    const slots = EDITORIAL.filter(s => byKey.has(s.key))

    // Repli : tout widget du catalogue hors template (autres archétypes : logistique,
    // listes conformité…) est ajouté ensuite à sa taille déclarée, ordre priorité.
    const templated = new Set(EDITORIAL.map(s => s.key))
    const FALLBACK_PRIORITY = [
      'shipments.today', 'shipments.in_transit', 'shipments.late', 'drivers.active',
      'deliveries.my_today', 'deliveries.next', 'deliveries.completed_today',
      'compliance.pending', 'compliance.overdue', 'compliance.roles', 'compliance.types',
    ]
    const frank = k => { const i = FALLBACK_PRIORITY.indexOf(k); return i === -1 ? 999 : i }
    const extras = catalog
      .filter(w => !templated.has(w.key))
      .sort((a, b) => frank(a.key) - frank(b.key))
      .map(w => ({ key: w.key, w: Math.min(w.layout?.default_w || 3, 12), h: w.layout?.default_h || 2 }))

    // Flow row-packing (12 col) — respecte la taille de chaque slot, retour à la ligne.
    const all = [...slots, ...extras]
    const layout = []
    let x = 0; let y = 0; let rowH = 0
    for (const s of all) {
      const w = Math.min(s.w, 12)
      if (x + w > 12) { x = 0; y += rowH; rowH = 0 }
      layout.push({ key: s.key, x, y, w, h: s.h, scope: 'company', config: byKey.get(s.key)?.default_config || {} })
      x += w
      rowH = Math.max(rowH, s.h)
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
