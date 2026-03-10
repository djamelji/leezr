import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformBillingFinancialStore = defineStore('platformBillingFinancial', {
  state: () => ({
    // Financial governance (D4b/D4c)
    _trialBalance: {},
    _ledgerEntries: [],
    _ledgerPagination: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
    _financialPeriods: [],
    _timeline: [],
    _snapshots: [],
    _snapshotsPagination: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
    _driftHistory: [],
    _financialLoading: false,
  }),

  getters: {
    trialBalance: state => state._trialBalance,
    ledgerEntries: state => state._ledgerEntries,
    ledgerPagination: state => state._ledgerPagination,
    financialPeriods: state => state._financialPeriods,
    timeline: state => state._timeline,
    snapshots: state => state._snapshots,
    snapshotsPagination: state => state._snapshotsPagination,
    driftHistory: state => state._driftHistory,
    financialLoading: state => state._financialLoading,
  },

  actions: {
    async fetchTrialBalance(companyId) {
      this._financialLoading = true
      try {
        const data = await $platformApi(`/billing/ledger/trial-balance?company_id=${companyId}`)

        this._trialBalance = data.balance || {}
      }
      finally {
        this._financialLoading = false
      }
    },

    async fetchLedgerEntries({ company_id, correlation_id, entry_type, page = 1 } = {}) {
      this._financialLoading = true
      try {
        const params = new URLSearchParams()

        params.set('company_id', company_id)
        params.set('page', page)
        if (correlation_id) params.set('correlation_id', correlation_id)
        if (entry_type) params.set('entry_type', entry_type)

        const data = await $platformApi(`/billing/ledger/entries?${params}`)

        this._ledgerEntries = data.data
        this._ledgerPagination = {
          current_page: data.current_page,
          last_page: data.last_page,
          per_page: data.per_page,
          total: data.total,
        }
      }
      finally {
        this._financialLoading = false
      }
    },

    async fetchFreezeState(companyId) {
      const data = await $platformApi(`/billing/companies/${companyId}/financial-freeze`)

      return data
    },

    async fetchFinancialPeriods(companyId) {
      const data = await $platformApi(`/billing/financial-periods?company_id=${companyId}`)

      this._financialPeriods = data.periods || []
    },

    async fetchTimeline({ company_id, days = 30, entity_type } = {}) {
      this._financialLoading = true
      try {
        const params = new URLSearchParams()

        params.set('company_id', company_id)
        params.set('days', days)
        if (entity_type) params.set('entity_type', entity_type)

        const data = await $platformApi(`/billing/forensics/timeline?${params}`)

        this._timeline = data.timeline || []
      }
      finally {
        this._financialLoading = false
      }
    },

    async fetchSnapshots(companyId, page = 1) {
      this._financialLoading = true
      try {
        const data = await $platformApi(`/billing/forensics/snapshots?company_id=${companyId}&page=${page}`)

        this._snapshots = data.data
        this._snapshotsPagination = {
          current_page: data.current_page,
          last_page: data.last_page,
          per_page: data.per_page,
          total: data.total,
        }
      }
      finally {
        this._financialLoading = false
      }
    },

    async fetchDriftHistory(companyId) {
      const data = await $platformApi(`/billing/drift-history?company_id=${companyId}`)

      this._driftHistory = data.drifts || []
    },

    // Mutations (manage_billing)

    async closeFinancialPeriod(payload) {
      this._financialLoading = true
      try {
        const data = await $platformApi('/billing/financial-periods/close', {
          method: 'POST',
          body: payload,
        })

        if (payload.company_id) {
          await this.fetchFinancialPeriods(payload.company_id)
        }

        return data
      }
      finally {
        this._financialLoading = false
      }
    },

    async toggleFinancialFreeze(companyId, frozen) {
      this._financialLoading = true
      try {
        const data = await $platformApi(`/billing/companies/${companyId}/financial-freeze`, {
          method: 'PUT',
          body: { frozen },
        })

        return data
      }
      finally {
        this._financialLoading = false
      }
    },

    async runReconcile(payload = {}) {
      this._financialLoading = true
      try {
        const data = await $platformApi('/billing/reconcile', {
          method: 'POST',
          body: { dry_run: true, ...payload },
        })

        return data
      }
      finally {
        this._financialLoading = false
      }
    },
  },
})
