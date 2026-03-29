import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { $guardedApi } from '@/utils/guardedApi'
import { useAppToast } from '@/composables/useAppToast'

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
      settings: false,
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
      catch {
        // Keep existing compliance data on error
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
    // ADR-418: $guardedApi — cross-permission defense (documents.manage vs page-level documents.view)
    // ADR-431b: silent mode — skeleton only on first load, polling passes { silent: true }
    async fetchRequests({ silent = false } = {}) {
      if (!silent) this._loading.requests = true

      try {
        const data = await $guardedApi('documents.manage', '/company/document-requests/queue')

        if (!data) return
        this._mergeRequests(data.queue ?? [])
      }
      catch {
        this._requests = []
      }
      finally {
        if (!silent) this._loading.requests = false
      }
    },

    /**
     * ADR-431b: Smart merge — only trigger reactivity when data actually changed.
     * Compares via JSON.stringify before Object.assign to avoid unnecessary re-renders.
     */
    _mergeRequests(incoming) {
      if (this._requests.length === 0) {
        this._requests = incoming

        return
      }

      const existingById = new Map(this._requests.map(r => [r.id, r]))

      const merged = incoming.map(item => {
        const existing = existingById.get(item.id)
        if (existing) {
          if (JSON.stringify(existing) !== JSON.stringify(item)) {
            Object.assign(existing, item)
          }

          return existing
        }

        return item
      })

      if (merged.length !== this._requests.length || merged.some((r, i) => r.id !== this._requests[i]?.id)) {
        this._requests = merged
      }
    },

    // ─── Document Settings ──────────────────────────────
    // ADR-418: $guardedApi — cross-permission defense (documents.configure vs page-level documents.view)
    async fetchDocSettings() {
      this._loading.settings = true

      try {
        const data = await $guardedApi('documents.configure', '/company/document-settings')

        if (!data) return
        this._docSettings = data.settings
      }
      catch {
        this._docSettings = null
      }
      finally {
        this._loading.settings = false
      }
    },

    async updateDocSettings(payload) {
      const { toast } = useAppToast()

      try {
        const data = await $api('/company/document-settings', { method: 'PUT', body: payload })

        this._docSettings = data.settings

        return data
      }
      catch (error) {
        toast(error?.data?.message || error.message || 'Error', 'error')
        throw error
      }
    },

    // ─── Custom Document Types ─────────────────────────
    async createCustomDocumentType(payload) {
      const { toast } = useAppToast()

      try {
        return await $api('/company/document-types/custom', { method: 'POST', body: payload })
      }
      catch (error) {
        toast(error?.data?.message || error.message || 'Error', 'error')
        throw error
      }
    },

    async updateCustomDocumentType(code, payload) {
      const { toast } = useAppToast()

      try {
        return await $api(`/company/document-types/custom/${code}`, { method: 'PUT', body: payload })
      }
      catch (error) {
        toast(error?.data?.message || error.message || 'Error', 'error')
        throw error
      }
    },

    async archiveCustomDocumentType(code) {
      const { toast } = useAppToast()

      try {
        return await $api(`/company/document-types/custom/${code}/archive`, { method: 'PUT' })
      }
      catch (error) {
        toast(error?.data?.message || error.message || 'Error', 'error')
        throw error
      }
    },

    async deleteCustomDocumentType(code) {
      const { toast } = useAppToast()

      try {
        return await $api(`/company/document-types/custom/${code}`, { method: 'DELETE' })
      }
      catch (error) {
        toast(error?.data?.message || error.message || 'Error', 'error')
        throw error
      }
    },

    // ─── Request Actions ──────────────────────────────────
    async cancelRequest(requestId) {
      const { toast } = useAppToast()

      try {
        await $api(`/company/document-requests/${requestId}/cancel`, { method: 'PUT' })
        await this.fetchRequests()
      }
      catch (error) {
        toast(error?.data?.message || error.message || 'Error', 'error')
        throw error
      }
    },

    async remindRequest(requestId) {
      const { toast } = useAppToast()

      try {
        await $api(`/company/document-requests/${requestId}/remind`, { method: 'PUT' })
        await this.fetchRequests()
      }
      catch (error) {
        toast(error?.data?.message || error.message || 'Error', 'error')
        throw error
      }
    },

    // ADR-423: Bulk approve/reject
    async bulkAction(ids, action, reviewNote = null) {
      const data = await $api('/company/document-requests/bulk-action', {
        method: 'POST',
        body: { ids, action, review_note: reviewNote },
      })

      await this.fetchRequests()

      return data
    },

    // ─── ADR-427: Realtime targeted updates ─────────────

    /**
     * Update a single request in the queue by matching criteria.
     * Used by SSE events to patch specific rows without full refetch.
     */
    updateRequestById(documentId, patch) {
      const idx = this._requests.findIndex(
        r => r.upload?.id === documentId || r.document_id === documentId,
      )
      if (idx !== -1) {
        this._requests[idx] = { ...this._requests[idx], ...patch }
      }
    },

    /**
     * Merge partial AI data into a request's upload object.
     * Used when ai_status changes from processing → completed.
     */
    mergeRequestAiData(documentId, aiData) {
      const req = this._requests.find(
        r => r.upload?.id === documentId || r.document_id === documentId,
      )
      if (req?.upload) {
        Object.assign(req.upload, aiData)
      }
    },

    /**
     * Handle a realtime domain event for documents.
     * Called by useRealtimeSubscription('document.updated', ...) in the page component.
     *
     * Strategy:
     * - ai.completed/ai.failed → patch the specific row's ai_status
     * - uploaded/reviewed/deleted/bulk_reviewed → soft refresh requests list
     * - Other → ignore (forward-compatible)
     */
    handleRealtimeEvent(payload) {
      const type = payload?.type

      if (type === 'ai.completed' || type === 'ai.failed') {
        // Targeted patch: only update the affected row
        const docId = payload.id
        if (docId) {
          this.mergeRequestAiData(docId, {
            ai_status: payload.ai_status,
            confidence: payload.confidence ?? null,
          })
        }
      }
      else if (['uploaded', 'reviewed', 'deleted', 'cancelled', 'requested', 'bulk_reviewed'].includes(type)) {
        // Soft refresh: debounced refetch of the affected lists
        this.fetchRequests()
      }
    },
  },
})
