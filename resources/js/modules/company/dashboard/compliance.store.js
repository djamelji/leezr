import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

/**
 * ADR-387: Compliance store — lifecycle-based document compliance for dashboard widgets.
 *
 * Fetches GET /api/company/documents/compliance and exposes
 * computed getters consumed by compliance widgets (self-contained).
 *
 * Replaces the old request-queue-based approach with lifecycle_status
 * aggregation (valid, missing, expiring_soon, expired).
 */
export const useCompanyComplianceStore = defineStore('companyCompliance', {
  state: () => ({
    _summary: { total: 0, valid: 0, missing: 0, expiring_soon: 0, expired: 0, rate: 0 },
    _byRole: [],
    _byType: [],
    _loading: false,
    _loaded: false,
  }),

  getters: {
    summary: state => state._summary,
    byRole: state => state._byRole,
    byType: state => state._byType,
    isLoading: state => state._loading,
    hasData: state => state._loaded && state._summary.total > 0,

    // KPI getters for widgets
    complianceRate: state => state._summary.rate,
    totalSlots: state => state._summary.total,
    validCount: state => state._summary.valid,
    missingCount: state => state._summary.missing,
    expiringSoonCount: state => state._summary.expiring_soon,
    expiredCount: state => state._summary.expired,
  },

  actions: {
    async fetchCompliance() {
      this._loading = true

      try {
        const data = await $api('/company/documents/compliance')

        this._summary = data.summary
        this._byRole = data.by_role
        this._byType = data.by_type
        this._loaded = true
      }
      finally {
        this._loading = false
      }
    },
  },
})
