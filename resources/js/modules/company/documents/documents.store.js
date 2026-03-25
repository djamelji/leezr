import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

/**
 * ADR-389: Centralized documents store for the company documents module.
 *
 * Consolidates document vault, activations, compliance, and request queue
 * into a single store consumed by /company/documents/[tab] page.
 */
export const useCompanyDocumentsStore = defineStore('companyDocuments', {
  state: () => ({
    // Vault (company-scope documents)
    _companyDocuments: [],

    // Activation catalog
    _documentActivations: { company_user_documents: [], company_documents: [] },

    // Compliance summary (ADR-387)
    _compliance: {
      summary: { total: 0, valid: 0, missing: 0, expiring_soon: 0, expired: 0, rate: 0 },
      by_role: [],
      by_type: [],
    },

    // Request queue
    _requests: [],

    // Activity feed
    _activity: [],

    // Document automation settings
    _docSettings: null,

    // Loading flags
    _loading: {
      vault: false,
      activations: false,
      compliance: false,
      requests: false,
      activity: false,
    },
  }),

  getters: {
    companyDocuments: state => state._companyDocuments,
    documentActivations: state => state._documentActivations,
    compliance: state => state._compliance,
    complianceSummary: state => state._compliance.summary,
    complianceByRole: state => state._compliance.by_role,
    complianceByType: state => state._compliance.by_type,
    requests: state => state._requests,
    activity: state => state._activity,
    docSettings: state => state._docSettings,
    loading: state => state._loading,

    // KPI getters
    complianceRate: state => state._compliance.summary.rate,
    missingCount: state => state._compliance.summary.missing,
    expiredCount: state => state._compliance.summary.expired,
    expiringSoonCount: state => state._compliance.summary.expiring_soon,
    pendingRequestsCount: state => state._requests.filter(r => r.status === 'requested').length,
    submittedRequestsCount: state => state._requests.filter(r => r.status === 'submitted').length,
  },

  actions: {
    // ─── Vault ─────────────────────────────────────────
    async fetchCompanyDocuments() {
      this._loading.vault = true

      try {
        const data = await $api('/company/documents')

        this._companyDocuments = data.documents || []
      }
      catch {
        this._companyDocuments = []
      }
      finally {
        this._loading.vault = false
      }
    },

    // ─── Activations ───────────────────────────────────
    async fetchDocumentActivations() {
      this._loading.activations = true

      try {
        const data = await $api('/company/document-activations')

        this._documentActivations = data
      }
      catch {
        this._documentActivations = { company_user_documents: [], company_documents: [] }
      }
      finally {
        this._loading.activations = false
      }
    },

    // ─── Compliance ────────────────────────────────────
    async fetchCompliance() {
      this._loading.compliance = true

      try {
        const data = await $api('/company/documents/compliance')

        this._compliance = {
          summary: data.summary,
          by_role: data.by_role,
          by_type: data.by_type,
        }
      }
      finally {
        this._loading.compliance = false
      }
    },

    // ─── Activity Feed ─────────────────────────────────
    async fetchActivity() {
      this._loading.activity = true

      try {
        const data = await $api('/company/documents/activity')

        this._activity = data.activity ?? []
      }
      catch {
        this._activity = []
      }
      finally {
        this._loading.activity = false
      }
    },

    // ─── Requests Queue ────────────────────────────────
    async fetchRequests() {
      this._loading.requests = true

      try {
        const data = await $api('/company/document-requests/queue')

        this._requests = data.queue ?? []
      }
      catch {
        this._requests = []
      }
      finally {
        this._loading.requests = false
      }
    },

    // ─── Document Settings ──────────────────────────────
    async fetchDocSettings() {
      try {
        const data = await $api('/company/document-settings')

        this._docSettings = data.settings
      }
      catch {
        this._docSettings = null
      }
    },

    async updateDocSettings(payload) {
      const data = await $api('/company/document-settings', { method: 'PUT', body: payload })

      this._docSettings = data.settings

      return data
    },

    // ─── Custom Document Types ─────────────────────────
    async createCustomDocumentType(payload) {
      return await $api('/company/document-types/custom', { method: 'POST', body: payload })
    },

    async archiveCustomDocumentType(code) {
      return await $api(`/company/document-types/custom/${code}/archive`, { method: 'PUT' })
    },

    async deleteCustomDocumentType(code) {
      return await $api(`/company/document-types/custom/${code}`, { method: 'DELETE' })
    },

    // ─── Request Actions ──────────────────────────────────
    async cancelRequest(requestId) {
      await $api(`/company/document-requests/${requestId}/cancel`, { method: 'PUT' })
      await this.fetchRequests()
    },

    async remindRequest(requestId) {
      await $api(`/company/document-requests/${requestId}/remind`, { method: 'PUT' })
      await this.fetchRequests()
    },
  },
})
