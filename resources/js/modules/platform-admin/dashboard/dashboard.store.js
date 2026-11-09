import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { $platformApi } from '@/utils/platformApi'
import { createSurfaceEngine } from '@/core/surface-engine/useSurfaceEngine'

export const useDashboardStore = defineStore('platformDashboard', () => {
  const engine = createSurfaceEngine({
    apiFn: $platformApi,
    scopeStrategy: 'explicit',
  })

  // ── Cockpit (ADR-441) ─────────────────────────────────────────────

  const attentionItems = ref([])
  const healthBadges = ref({})
  const cockpitLoading = ref(false)

  async function fetchAttention() {
    const data = await $platformApi('/dashboard/attention')

    attentionItems.value = data.items
  }

  async function fetchHealth() {
    const data = await $platformApi('/dashboard/health')

    healthBadges.value = data.badges
  }

  async function loadCockpit() {
    cockpitLoading.value = true
    try {
      await Promise.all([fetchAttention(), fetchHealth()])
    }
    catch {
      // ADR-598 (Phase 2 deploy): swallow (e.g. 403 for a role without view_dashboard) so a
      // restricted user never triggers an uncaught rejection. API stays server-enforced.
      attentionItems.value = []
      healthBadges.value = {}
    }
    finally {
      cockpitLoading.value = false
    }
  }

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
   * Composition éditoriale du dashboard Platform (ADR-746, P2).
   * Unité = la PAGE. 3 sections adaptatives (Commercial, Opérationnel, Support/
   * Sécurité/Catalogue), chacune émise seulement si ≥1 de ses widgets est présent
   * dans le catalogue — lequel est déjà filtré par les permissions de l'utilisateur
   * (catalogForUser). Largeurs sommant à 12 → pas de trous. Les anciens KPI billing
   * individuels sont EXCLUS du défaut (couverts par le panneau commercial) mais
   * restent au catalogue ; les widgets AI (non couverts) sont ajoutés en repli pour
   * qu'aucun utilisateur autorisé n'obtienne un dashboard vide.
   */
  function buildSmartDefaultLayout() {
    const catalog = engine._catalog.value
    const byKey = new Map(catalog.map(w => [w.key, w]))
    const has = k => byKey.has(k)

    const SECTIONS = [
      // Commercial — hero (h4)
      [
        { key: 'platform.commercial_overview', w: 8, h: 4 },
        { key: 'platform.plan_distribution', w: 4, h: 4 },
      ],
      // Opérationnel (h3)
      [
        { key: 'platform.companies_overview', w: 6, h: 3 },
        { key: 'platform.system_health', w: 6, h: 3 },
      ],
      // Support / Sécurité / Catalogue (h3)
      [
        { key: 'platform.support_overview', w: 4, h: 3 },
        { key: 'platform.security_overview', w: 4, h: 3 },
        { key: 'platform.catalog_overview', w: 4, h: 3 },
      ],
    ]
    const templated = new Set(SECTIONS.flat().map(s => s.key))

    const layout = []
    const cur = { x: 0, y: 0, rowH: 0 }
    const place = (key, w, h) => {
      const ww = Math.min(w, 12)
      if (cur.x + ww > 12) { cur.x = 0; cur.y += cur.rowH; cur.rowH = 0 }
      layout.push({ key, x: cur.x, y: cur.y, w: ww, h, scope: 'global', config: byKey.get(key)?.default_config || {} })
      cur.x += ww
      cur.rowH = Math.max(cur.rowH, h)
    }
    const newRow = () => { cur.x = 0; cur.y += cur.rowH; cur.rowH = 0 }

    for (const section of SECTIONS) {
      const slots = section.filter(s => has(s.key))
      if (!slots.length) continue
      newRow()
      for (const s of slots) place(s.key, s.w, s.h)
    }

    // Repli : widgets autorisés hors composites et NON billing (ex. AI) — jamais perdus.
    newRow()
    for (const w of catalog) {
      if (templated.has(w.key) || w.key.startsWith('billing.')) continue
      place(w.key, Math.min(w.layout?.default_w || 4, 12), w.layout?.default_h || 2)
    }

    engine._layout.value = layout
    engine._dirty.value = true
  }

  // Override loadDashboard — compose l'éditorial quand aucun layout utilisateur n'existe.
  async function loadDashboard() {
    await Promise.all([engine.fetchCatalog(), engine.fetchLayout()])

    // Backend renvoie [] par défaut (ADR-746) → on compose côté client, filtré par le
    // catalogue permissionné. Jamais écraser sur erreur de fetch (ADR-326). PUT layout
    // n'exige que `view_dashboard` (= droit de voir) → save sans risque de 403.
    if (engine._fetchLayoutSucceeded.value && !engine._layout.value.length && engine._catalog.value.length) {
      buildSmartDefaultLayout()
      try {
        await engine.saveLayout()
      }
      catch {
        // Non bloquant — layout rendu en mémoire.
      }
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
    // Cockpit (ADR-441)
    attentionItems,
    healthBadges,
    cockpitLoading,
    loadCockpit,
  }
})
