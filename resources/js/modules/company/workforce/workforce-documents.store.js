import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useWorkforceDocumentsStore = defineStore('workforceDocuments', {
  state: () => ({
    _documents: [],
    _totalDocuments: 0,
    _currentDocument: null,
    _statistics: null,
    _payrollRunDocuments: [],
    _loading: false,
  }),

  getters: {
    documents: state => state._documents,
    totalDocuments: state => state._totalDocuments,
    currentDocument: state => state._currentDocument,
    statistics: state => state._statistics,
    payrollRunDocuments: state => state._payrollRunDocuments,
    loading: state => state._loading,
  },

  actions: {
    // ── Documents ────────────────────────────────────────────
    async fetchDocuments({ subjectType, templateCode, signatureStatus, perPage, page } = {}) {
      this._loading = true
      try {
        const params = new URLSearchParams()

        if (subjectType) params.set('subject_type', subjectType)
        if (templateCode) params.set('template_code', templateCode)
        if (signatureStatus) params.set('signature_status', signatureStatus)
        if (perPage) params.set('per_page', perPage)
        if (page) params.set('page', page)

        const qs = params.toString()
        const data = await $api(`/workforce/documents${qs ? `?${qs}` : ''}`)

        this._documents = data.data ?? []
        this._totalDocuments = data.total ?? 0

        return data
      } finally {
        this._loading = false
      }
    },

    async fetchDocument(id) {
      this._loading = true
      try {
        const data = await $api(`/workforce/documents/${id}`)

        this._currentDocument = data

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Statistics ───────────────────────────────────────────
    async fetchStatistics() {
      this._loading = true
      try {
        const data = await $api('/workforce/documents/statistics')

        this._statistics = data

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Generate ─────────────────────────────────────────────
    async generateDocument({ templateCode, subjectType, subjectId, forceVault }) {
      this._loading = true
      try {
        const body = {
          template_code: templateCode,
          subject_type: subjectType,
          subject_id: subjectId,
        }

        if (forceVault !== undefined) body.force_vault = forceVault

        const data = await $api('/workforce/documents/generate', {
          method: 'POST',
          body,
        })

        this._documents.unshift(data)
        this._totalDocuments++

        return data
      } finally {
        this._loading = false
      }
    },

    async generatePayslipDrafts(payrollRunId) {
      this._loading = true
      try {
        const data = await $api('/workforce/documents/payslip-drafts', {
          method: 'POST',
          body: { payroll_run_id: payrollRunId },
        })

        return data
      } finally {
        this._loading = false
      }
    },

    async generateOfficialPayslips(payrollRunId) {
      this._loading = true
      try {
        const data = await $api('/workforce/documents/payslip-official', {
          method: 'POST',
          body: { payroll_run_id: payrollRunId },
        })

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Payroll Run Documents ────────────────────────────────
    async fetchPayrollRunDocuments(runId) {
      this._loading = true
      try {
        const data = await $api(`/workforce/documents/payroll-run/${runId}`)

        this._payrollRunDocuments = data

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Reset ────────────────────────────────────────────────
    clearCurrentDocument() {
      this._currentDocument = null
    },
  },
})
