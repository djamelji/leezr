import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformPaymentsStore = defineStore('platformPayments', {
  state: () => ({
    _providers: [],
    _config: { driver: 'null', config: {} },
    _policies: {
      payment_required: false,
      annual_only: false,
      vat_enabled: false,
      vat_rate: 0,
      max_payment_methods: 4,
    },
    _subscriptions: [],
    _subscriptionsPagination: { current_page: 1, last_page: 1, total: 0 },
    _paymentModules: [],
    _paymentRules: [],
    _previewMethods: [],

    // Invoice detail
    _invoiceDetail: null,

    // Invoice mutation loading locks (ADR-135 D2b)
    _mutationLoading: {},

    // Read-only billing data (view_billing)
    _allInvoices: [],
    _allInvoicesPagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    _allPayments: [],
    _allPaymentsPagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    _allCreditNotes: [],
    _allCreditNotesPagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    _allWallets: [],
    _allWalletsPagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    _allSubscriptions: [],
    _allSubscriptionsPagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    _dunningInvoices: [],
    _dunningPagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },

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

    // Billing widgets (D4e)
    _widgets: [],
    _widgetData: {},
    _widgetsLoading: false,
    _widgetLoading: {},
    _widgetsPeriod: '30d',

    // Billing metrics (ADR-227)
    _metrics: null,
    _metricsLoading: false,

    // Recovery status (ADR-236)
    _recoveryStatus: null,
    _recoveryLoading: false,
  }),

  getters: {
    providers: state => state._providers,
    config: state => state._config,
    policies: state => state._policies,
    subscriptions: state => state._subscriptions,
    subscriptionsPagination: state => state._subscriptionsPagination,
    paymentModules: state => state._paymentModules,
    paymentRules: state => state._paymentRules,
    previewMethods: state => state._previewMethods,

    // Invoice detail
    invoiceDetail: state => state._invoiceDetail,

    // Mutation loading lock
    mutationLoading: state => state._mutationLoading,

    // Read-only billing getters
    allInvoices: state => state._allInvoices,
    allInvoicesPagination: state => state._allInvoicesPagination,
    allPayments: state => state._allPayments,
    allPaymentsPagination: state => state._allPaymentsPagination,
    allCreditNotes: state => state._allCreditNotes,
    allCreditNotesPagination: state => state._allCreditNotesPagination,
    allWallets: state => state._allWallets,
    allWalletsPagination: state => state._allWalletsPagination,
    allSubscriptions: state => state._allSubscriptions,
    allSubscriptionsPagination: state => state._allSubscriptionsPagination,
    dunningInvoices: state => state._dunningInvoices,
    dunningPagination: state => state._dunningPagination,

    // Financial governance getters
    trialBalance: state => state._trialBalance,
    ledgerEntries: state => state._ledgerEntries,
    ledgerPagination: state => state._ledgerPagination,
    financialPeriods: state => state._financialPeriods,
    timeline: state => state._timeline,
    snapshots: state => state._snapshots,
    snapshotsPagination: state => state._snapshotsPagination,
    driftHistory: state => state._driftHistory,
    financialLoading: state => state._financialLoading,

    // Billing widgets getters
    widgets: state => state._widgets,
    widgetData: state => state._widgetData,
    widgetsLoading: state => state._widgetsLoading,
    widgetLoading: state => state._widgetLoading,
    widgetsPeriod: state => state._widgetsPeriod,

    // Billing metrics
    metrics: state => state._metrics,
    metricsLoading: state => state._metricsLoading,

    // Recovery status
    recoveryStatus: state => state._recoveryStatus,
    recoveryLoading: state => state._recoveryLoading,
  },

  actions: {
    async fetchProviders() {
      const data = await $platformApi('/billing/providers')

      this._providers = data.providers
    },

    async fetchConfig() {
      const data = await $platformApi('/billing/config')

      this._config = data.billing
    },

    async updateConfig(payload) {
      const data = await $platformApi('/billing/config', {
        method: 'PUT',
        body: payload,
      })

      this._config = data.billing

      return data
    },

    async fetchPolicies() {
      const data = await $platformApi('/billing/policies')

      this._policies = data.policies
    },

    async updatePolicies(payload) {
      const data = await $platformApi('/billing/policies', {
        method: 'PUT',
        body: payload,
      })

      this._policies = data.policies

      return data
    },

    async fetchSubscriptions(page = 1) {
      const data = await $platformApi('/billing/subscriptions', {
        params: { page },
      })

      this._subscriptions = data.data
      this._subscriptionsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },

    async approveSubscription(id) {
      const data = await $platformApi(`/billing/subscriptions/${id}/approve`, {
        method: 'PUT',
      })

      this._updateSubscriptionInList(data.subscription)

      return data
    },

    async rejectSubscription(id) {
      const data = await $platformApi(`/billing/subscriptions/${id}/reject`, {
        method: 'PUT',
      })

      this._updateSubscriptionInList(data.subscription)

      return data
    },

    _updateSubscriptionInList(subscription) {
      const idx = this._subscriptions.findIndex(s => s.id === subscription.id)
      if (idx !== -1)
        this._subscriptions[idx] = subscription
    },

    // Payment modules (ADR-124)
    async fetchPaymentModules() {
      const data = await $platformApi('/billing/payment-modules')

      this._paymentModules = data.modules
    },

    async installPaymentModule(providerKey) {
      const data = await $platformApi(`/billing/payment-modules/${providerKey}/install`, {
        method: 'PUT',
      })

      await this.fetchPaymentModules()

      return data
    },

    async activatePaymentModule(providerKey) {
      const data = await $platformApi(`/billing/payment-modules/${providerKey}/activate`, {
        method: 'PUT',
      })

      await this.fetchPaymentModules()

      return data
    },

    async deactivatePaymentModule(providerKey) {
      const data = await $platformApi(`/billing/payment-modules/${providerKey}/deactivate`, {
        method: 'PUT',
      })

      await this.fetchPaymentModules()

      return data
    },

    async updatePaymentModuleCredentials(providerKey, credentials) {
      const data = await $platformApi(`/billing/payment-modules/${providerKey}/credentials`, {
        method: 'PUT',
        body: { credentials },
      })

      return data
    },

    async checkPaymentModuleHealth(providerKey) {
      const data = await $platformApi(`/billing/payment-modules/${providerKey}/health`)

      await this.fetchPaymentModules()

      return data
    },

    // Payment method rules (ADR-124)
    async fetchPaymentRules() {
      const data = await $platformApi('/billing/payment-rules')

      this._paymentRules = data.rules
    },

    async createPaymentRule(payload) {
      const data = await $platformApi('/billing/payment-rules', {
        method: 'POST',
        body: payload,
      })

      await this.fetchPaymentRules()

      return data
    },

    async updatePaymentRule(id, payload) {
      const data = await $platformApi(`/billing/payment-rules/${id}`, {
        method: 'PUT',
        body: payload,
      })

      await this.fetchPaymentRules()

      return data
    },

    async deletePaymentRule(id) {
      const data = await $platformApi(`/billing/payment-rules/${id}`, {
        method: 'DELETE',
      })

      await this.fetchPaymentRules()

      return data
    },

    async previewPaymentMethods(params) {
      const data = await $platformApi('/billing/payment-rules/preview', {
        params,
      })

      this._previewMethods = data.methods

      return data
    },

    // ── Invoice detail ──

    async fetchInvoiceDetail(id) {
      const data = await $platformApi(`/billing/invoices/${id}`)

      this._invoiceDetail = data.invoice
    },

    // ── Read-only billing data (view_billing, ADR-135 Phase A) ──

    async fetchAllInvoices({ page = 1, company_id, status } = {}) {
      const params = new URLSearchParams()

      params.set('page', page)
      if (company_id) params.set('company_id', company_id)
      if (status) params.set('status', status)

      const data = await $platformApi(`/billing/invoices?${params}`)

      this._allInvoices = data.data
      this._allInvoicesPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
      }
    },

    async fetchAllPayments({ page = 1, company_id, status } = {}) {
      const params = new URLSearchParams()

      params.set('page', page)
      if (company_id) params.set('company_id', company_id)
      if (status) params.set('status', status)

      const data = await $platformApi(`/billing/payments?${params}`)

      this._allPayments = data.data
      this._allPaymentsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
      }
    },

    async fetchAllCreditNotes({ page = 1, company_id, status } = {}) {
      const params = new URLSearchParams()

      params.set('page', page)
      if (company_id) params.set('company_id', company_id)
      if (status) params.set('status', status)

      const data = await $platformApi(`/billing/credit-notes?${params}`)

      this._allCreditNotes = data.data
      this._allCreditNotesPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
      }
    },

    async fetchAllWallets({ page = 1, company_id } = {}) {
      const params = new URLSearchParams()

      params.set('page', page)
      if (company_id) params.set('company_id', company_id)

      const data = await $platformApi(`/billing/wallets?${params}`)

      this._allWallets = data.data
      this._allWalletsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
      }
    },

    async fetchAllSubscriptions({ page = 1, company_id, status, plan_key } = {}) {
      const params = new URLSearchParams()

      params.set('page', page)
      if (company_id) params.set('company_id', company_id)
      if (status) params.set('status', status)
      if (plan_key) params.set('plan_key', plan_key)

      const data = await $platformApi(`/billing/all-subscriptions?${params}`)

      this._allSubscriptions = data.data
      this._allSubscriptionsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
      }
    },

    async fetchDunning({ page = 1 } = {}) {
      const data = await $platformApi(`/billing/dunning?page=${page}`)

      this._dunningInvoices = data.data
      this._dunningPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
      }
    },

    // ── Invoice mutations (ADR-135 D2b) ──

    isMutationLoading(invoiceId) {
      return !!this._mutationLoading[invoiceId]
    },

    async markPaidOffline(invoiceId, idempotencyKey) {
      this._mutationLoading[invoiceId] = true
      try {
        const data = await $platformApi(`/billing/invoices/${invoiceId}/mark-paid-offline`, {
          method: 'PUT',
          body: { idempotency_key: idempotencyKey },
        })

        await this.fetchAllInvoices({
          page: this._allInvoicesPagination.current_page,
        })

        return data
      }
      finally {
        delete this._mutationLoading[invoiceId]
      }
    },

    async voidInvoice(invoiceId, idempotencyKey) {
      this._mutationLoading[invoiceId] = true
      try {
        const data = await $platformApi(`/billing/invoices/${invoiceId}/void`, {
          method: 'PUT',
          body: { idempotency_key: idempotencyKey },
        })

        await this.fetchAllInvoices({
          page: this._allInvoicesPagination.current_page,
        })

        return data
      }
      finally {
        delete this._mutationLoading[invoiceId]
      }
    },

    async updateInvoiceNotes(invoiceId, notes) {
      this._mutationLoading[invoiceId] = true
      try {
        const data = await $platformApi(`/billing/invoices/${invoiceId}/notes`, {
          method: 'PUT',
          body: { notes },
        })

        await this.fetchAllInvoices({
          page: this._allInvoicesPagination.current_page,
        })

        return data
      }
      finally {
        delete this._mutationLoading[invoiceId]
      }
    },

    // ── Advanced invoice mutations (ADR-136 D2c / D4a) ──

    async refundInvoice(invoiceId, payload) {
      this._mutationLoading[invoiceId] = true
      try {
        const data = await $platformApi(`/billing/invoices/${invoiceId}/refund`, {
          method: 'POST',
          body: payload,
        })

        await this.fetchAllInvoices({
          page: this._allInvoicesPagination.current_page,
        })

        return data
      }
      finally {
        delete this._mutationLoading[invoiceId]
      }
    },

    async retryInvoicePayment(invoiceId, payload) {
      this._mutationLoading[invoiceId] = true
      try {
        const data = await $platformApi(`/billing/invoices/${invoiceId}/retry-payment`, {
          method: 'POST',
          body: payload,
        })

        await this.fetchAllInvoices({
          page: this._allInvoicesPagination.current_page,
        })

        return data
      }
      finally {
        delete this._mutationLoading[invoiceId]
      }
    },

    async forceDunningTransition(invoiceId, payload) {
      this._mutationLoading[invoiceId] = true
      try {
        const data = await $platformApi(`/billing/invoices/${invoiceId}/dunning-transition`, {
          method: 'PUT',
          body: payload,
        })

        await this.fetchAllInvoices({
          page: this._allInvoicesPagination.current_page,
        })

        return data
      }
      finally {
        delete this._mutationLoading[invoiceId]
      }
    },

    async issueManualCreditNote(invoiceId, payload) {
      this._mutationLoading[invoiceId] = true
      try {
        const data = await $platformApi(`/billing/invoices/${invoiceId}/credit-note`, {
          method: 'POST',
          body: payload,
        })

        await this.fetchAllInvoices({
          page: this._allInvoicesPagination.current_page,
        })

        return data
      }
      finally {
        delete this._mutationLoading[invoiceId]
      }
    },

    async writeOffInvoice(invoiceId, payload) {
      this._mutationLoading[invoiceId] = true
      try {
        const data = await $platformApi(`/billing/invoices/${invoiceId}/write-off`, {
          method: 'PUT',
          body: payload,
        })

        await this.fetchAllInvoices({
          page: this._allInvoicesPagination.current_page,
        })

        return data
      }
      finally {
        delete this._mutationLoading[invoiceId]
      }
    },

    // ── Financial governance (D4b/D4c) ──

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

    // ── Billing widgets (D4e) ──────────────────────────────

    async fetchWidgets(companyId) {
      this._widgetsLoading = true
      try {
        const data = await $platformApi('/billing/widgets', {
          params: { company_id: companyId },
        })

        this._widgets = data.widgets || []
      }
      finally {
        this._widgetsLoading = false
      }
    },

    async fetchWidget(key, companyId, period) {
      this._widgetLoading = { ...this._widgetLoading, [key]: true }
      try {
        const data = await $platformApi(`/billing/widgets/${key}`, {
          params: { company_id: companyId, period },
        })

        this._widgetData = { ...this._widgetData, [key]: data.data }
      }
      finally {
        this._widgetLoading = { ...this._widgetLoading, [key]: false }
      }
    },

    setWidgetsPeriod(period) {
      this._widgetsPeriod = period
    },

    async fetchAllWidgets(companyId) {
      await this.fetchWidgets(companyId)

      const period = this._widgetsPeriod
      await Promise.all(
        this._widgets.map(w => this.fetchWidget(w.key, companyId, period)),
      )
    },

    async fetchMetrics() {
      this._metricsLoading = true
      try {
        this._metrics = await $platformApi('/billing/metrics')
      }
      finally {
        this._metricsLoading = false
      }
    },

    async fetchRecoveryStatus() {
      this._recoveryLoading = true
      try {
        this._recoveryStatus = await $platformApi('/billing/recovery-status')
      }
      finally {
        this._recoveryLoading = false
      }
    },
  },
})
