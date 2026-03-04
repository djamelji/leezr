import { ref, computed } from 'vue'
import { firstFitPosition } from './useSurfaceFirstFit'

/**
 * Dashboard engine factory — shared by platform and company surfaces (ADR-197).
 *
 * Returns reactive state, computed getters, and actions for use inside
 * a Pinia `defineStore()` setup function. Surface-specific behaviour is
 * controlled by `config.scopeStrategy`:
 *
 *   'explicit' (platform) — resolveWidgets sends scope + company_id per widget
 *   'implicit' (company)  — resolveWidgets sends key + period only (company inferred by middleware)
 *
 * @param {{ apiFn: Function, scopeStrategy: 'explicit'|'implicit' }} config
 */
export function createSurfaceEngine(config) {
  const { apiFn, scopeStrategy } = config

  // ── State ──────────────────────────────────────────────────────────

  const _clientOnlyKeys = new Set()
  const _catalog = ref([])
  const _catalogLoading = ref(false)
  const _layout = ref([])
  const _layoutLoading = ref(false)
  const _widgetData = ref({})
  const _widgetErrors = ref({})
  const _dataLoading = ref(false)
  const _dirty = ref(false)

  // ── Getters ────────────────────────────────────────────────────────

  const catalog = computed(() => _catalog.value)
  const catalogLoading = computed(() => _catalogLoading.value)
  const layout = computed(() => _layout.value)
  const layoutLoading = computed(() => _layoutLoading.value)
  const widgetData = computed(() => _widgetData.value)
  const widgetErrors = computed(() => _widgetErrors.value)
  const dataLoading = computed(() => _dataLoading.value)
  const isDirty = computed(() => _dirty.value)
  const isLoading = computed(() => _catalogLoading.value || _layoutLoading.value || _dataLoading.value)
  const hasDashboardWidgets = computed(() => _layout.value.length > 0)

  // ── Actions ────────────────────────────────────────────────────────

  async function fetchCatalog() {
    _catalogLoading.value = true

    try {
      const data = await apiFn('/dashboard/widgets/catalog')

      _catalog.value = data.widgets
    }
    finally {
      _catalogLoading.value = false
    }
  }

  async function fetchLayout() {
    _layoutLoading.value = true

    try {
      const data = await apiFn('/dashboard/layout')

      _layout.value = data.layout
      _dirty.value = false
    }
    finally {
      _layoutLoading.value = false
    }
  }

  const _saveError = ref(null)
  const saveError = computed(() => _saveError.value)

  async function saveLayout() {
    _saveError.value = null

    try {
      // Filter out client-only widgets (e.g. compliance) that the backend doesn't know about
      const persistable = _layout.value.filter(t => !_clientOnlyKeys.has(t.key))

      // Nothing to persist — mark clean without API call
      if (!persistable.length) {
        _dirty.value = false

        return
      }

      const data = await apiFn('/dashboard/layout', {
        method: 'PUT',
        body: { layout: persistable },
      })

      // Merge backend response with client-only tiles (preserve their positions)
      const clientOnly = _layout.value.filter(t => _clientOnlyKeys.has(t.key))

      _layout.value = [...data.layout, ...clientOnly]
      _dirty.value = false
    }
    catch (err) {
      _saveError.value = err?.data?.message || err?.message || 'Save failed'
      throw err
    }
  }

  async function resolveWidgets() {
    if (!_layout.value.length) return
    if (_layout.value.every(t => _clientOnlyKeys.has(t.key))) return

    _dataLoading.value = true
    _widgetErrors.value = {}

    try {
      // Only request data for backend-registered widgets
      const requests = _layout.value
        .filter(item => !_clientOnlyKeys.has(item.key))
        .map(item => {
          const req = { key: item.key, period: item.config?.period || '30d' }

          if (scopeStrategy === 'explicit') {
            req.scope = item.scope
            req.company_id = item.config?.company_id || null
          }

          return req
        })

      const data = await apiFn('/dashboard/widgets/data', {
        method: 'POST',
        body: { widgets: requests },
      })

      const newData = {}
      const newErrors = {}

      for (const result of data.results) {
        if (result.data) {
          newData[result.key] = result.data
        }
        else if (result.error) {
          newErrors[result.key] = result.error
        }
      }

      _widgetData.value = newData
      _widgetErrors.value = newErrors
    }
    finally {
      _dataLoading.value = false
    }
  }

  async function loadDashboard() {
    await Promise.all([fetchCatalog(), fetchLayout()])
    await resolveWidgets()
  }

  /**
   * Add a widget from the catalog to the layout using first-fit placement.
   *
   * @param {import('./surfaceEngine.types').WidgetCatalogEntry} catalogEntry
   */
  function addWidget(catalogEntry) {
    if (_layout.value.find(i => i.key === catalogEntry.key)) return

    const dims = catalogEntry.layout || {}
    const w = dims.default_w || 4
    const h = dims.default_h || 4
    const { x, y } = firstFitPosition(_layout.value, w, h)

    const scope = scopeStrategy === 'explicit'
      ? (catalogEntry.scope === 'company' ? 'company' : 'global')
      : 'company'

    _layout.value.push({
      key: catalogEntry.key,
      x,
      y,
      w,
      h,
      scope,
      config: catalogEntry.default_config || {},
    })
    _dirty.value = true
  }

  function removeWidget(key) {
    _layout.value = _layout.value.filter(i => i.key !== key)
    delete _widgetData.value[key]
    delete _widgetErrors.value[key]
    _dirty.value = true
  }

  function updateLayout(newLayout) {
    _layout.value = newLayout
    _dirty.value = true
  }

  /**
   * Inject catalog entries client-side (e.g. compliance widgets).
   * Deduplicates by key — entries already present are skipped.
   * Injected entries are marked as client-only: excluded from save and resolve.
   *
   * @param {import('./surfaceEngine.types').WidgetCatalogEntry[]} entries
   */
  function injectCatalogEntries(entries) {
    for (const entry of entries) {
      _clientOnlyKeys.add(entry.key)
      if (!_catalog.value.find(w => w.key === entry.key)) {
        _catalog.value.push(entry)
      }
    }
  }

  return {
    // Internal refs (for surface-specific extensions to access)
    _catalog, _catalogLoading, _layout, _layoutLoading,
    _widgetData, _widgetErrors, _dataLoading, _dirty, _saveError,

    // Getters
    catalog, catalogLoading, layout, layoutLoading,
    widgetData, widgetErrors, dataLoading,
    isDirty, isLoading, hasDashboardWidgets, saveError,

    // Actions
    fetchCatalog, fetchLayout, saveLayout, resolveWidgets,
    loadDashboard, addWidget, removeWidget, updateLayout,
    injectCatalogEntries,
  }
}
