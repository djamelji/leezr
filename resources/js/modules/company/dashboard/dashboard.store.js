import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useCompanyDashboardStore = defineStore('companyDashboard', {
  state: () => ({
    _catalog: [],
    _catalogLoading: false,
    _layout: [],
    _layoutLoading: false,
    _widgetData: {},
    _widgetErrors: {},
    _dataLoading: false,
    _suggestions: [],
    _dirty: false,
  }),

  getters: {
    catalog: state => state._catalog,
    catalogLoading: state => state._catalogLoading,
    layout: state => state._layout,
    layoutLoading: state => state._layoutLoading,
    widgetData: state => state._widgetData,
    widgetErrors: state => state._widgetErrors,
    dataLoading: state => state._dataLoading,
    suggestions: state => state._suggestions,
    isDirty: state => state._dirty,
    isLoading: state => state._catalogLoading || state._layoutLoading || state._dataLoading,
  },

  actions: {
    async fetchCatalog() {
      this._catalogLoading = true

      try {
        const data = await $api('/dashboard/widgets/catalog')

        this._catalog = data.widgets
      }
      finally {
        this._catalogLoading = false
      }
    },

    async fetchLayout() {
      this._layoutLoading = true

      try {
        const data = await $api('/dashboard/layout')

        this._layout = data.layout
        this._dirty = false
      }
      finally {
        this._layoutLoading = false
      }
    },

    async saveLayout() {
      const data = await $api('/dashboard/layout', {
        method: 'PUT',
        body: { layout: this._layout },
      })

      this._layout = data.layout
      this._dirty = false
    },

    async resolveWidgets() {
      if (!this._layout.length) return

      this._dataLoading = true
      this._widgetErrors = {}

      try {
        const requests = this._layout.map(item => ({
          key: item.key,
          period: item.config?.period || '30d',
        }))

        const data = await $api('/dashboard/widgets/data', {
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

        this._widgetData = newData
        this._widgetErrors = newErrors
      }
      finally {
        this._dataLoading = false
      }
    },

    async fetchSuggestions() {
      const data = await $api('/dashboard/suggestions')

      this._suggestions = data.suggestions
    },

    async loadDashboard() {
      await Promise.all([this.fetchCatalog(), this.fetchLayout(), this.fetchSuggestions()])
      await this.resolveWidgets()
    },

    // ── Layout mutations ──

    addWidget(catalogEntry) {
      if (this._layout.find(i => i.key === catalogEntry.key)) return

      const dims = catalogEntry.layout || {}
      const w = dims.default_w || 4
      const h = dims.default_h || 4

      // First-fit: scan top-to-bottom, left-to-right for the first gap
      const maxY = this._layout.length
        ? this._layout.reduce((max, t) => Math.max(max, t.y + t.h), 0)
        : 0

      let x = 0
      let y = maxY

      for (let row = 0; row <= maxY; row++) {
        let found = false

        for (let col = 0; col <= 12 - w; col++) {
          const overlaps = this._layout.some(t =>
            col < t.x + t.w && col + w > t.x
            && row < t.y + t.h && row + h > t.y,
          )

          if (!overlaps) {
            x = col
            y = row
            found = true
            break
          }
        }
        if (found) break
      }

      this._layout.push({
        key: catalogEntry.key,
        x,
        y,
        w,
        h,
        scope: 'company',
        config: catalogEntry.default_config || {},
      })
      this._dirty = true
    },

    removeWidget(key) {
      this._layout = this._layout.filter(i => i.key !== key)
      delete this._widgetData[key]
      delete this._widgetErrors[key]
      this._dirty = true
    },

    updateLayout(newLayout) {
      this._layout = newLayout
      this._dirty = true
    },
  },
})
