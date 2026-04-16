import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

/**
 * ADR-440: Platform Activity Feed store.
 *
 * Unified event journal — reads from both platform + company audit logs
 * via the /activity endpoint with human-readable descriptions.
 */
export const usePlatformActivityStore = defineStore('platformActivity', {
  state: () => ({
    _items: [],
    _pagination: { current_page: 1, last_page: 1, total: 0, per_page: 30 },
    _types: { categories: [], actions_by_category: {} },
    _filters: {
      type: null,
      severity: null,
      actor_id: null,
      date_from: null,
      date_to: null,
    },
    _loading: false,
    _typesLoaded: false,
  }),

  getters: {
    items: s => s._items,
    pagination: s => s._pagination,
    types: s => s._types,
    filters: s => s._filters,
    loading: s => s._loading,
    typesLoaded: s => s._typesLoaded,
    totalEvents: s => s._pagination.total,
  },

  actions: {
    async fetchActivity(page = 1) {
      this._loading = true
      try {
        const params = { page, per_page: 30 }

        // Apply active filters
        if (this._filters.type) params.type = this._filters.type
        if (this._filters.severity) params.severity = this._filters.severity
        if (this._filters.actor_id) params.actor_id = this._filters.actor_id
        if (this._filters.date_from) params.date_from = this._filters.date_from
        if (this._filters.date_to) params.date_to = this._filters.date_to

        const data = await $platformApi('/activity', { params })

        this._items = data.data
        this._pagination = {
          current_page: data.current_page,
          last_page: data.last_page,
          total: data.total,
          per_page: data.per_page,
        }
      }
      finally {
        this._loading = false
      }
    },

    async fetchTypes() {
      if (this._typesLoaded) return

      const data = await $platformApi('/activity/types')

      this._types = data
      this._typesLoaded = true
    },

    setFilter(key, value) {
      this._filters[key] = value || null
    },

    resetFilters() {
      this._filters = {
        type: null,
        severity: null,
        actor_id: null,
        date_from: null,
        date_to: null,
      }
    },
  },
})
