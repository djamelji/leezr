import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformBillingInvoicesStore = defineStore('platformBillingInvoices', {
  state: () => ({
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
  }),

  getters: {
    invoiceDetail: state => state._invoiceDetail,
    mutationLoading: state => state._mutationLoading,
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
  },

  actions: {
    // ── Invoice detail ──

    async fetchInvoiceDetail(id) {
      const data = await $platformApi(`/billing/invoices/${id}`)

      this._invoiceDetail = data.invoice
    },

    // ── Read-only billing data (view_billing, ADR-135 Phase A) ──

    async fetchAllInvoices({ page = 1, company_id, status, search, from, to } = {}) {
      const params = new URLSearchParams()

      params.set('page', page)
      if (company_id) params.set('company_id', company_id)
      if (status) params.set('status', status)
      if (search) params.set('search', search)
      if (from) params.set('from', from)
      if (to) params.set('to', to)

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

    // ── Bulk actions (ADR-315) ──

    async bulkVoidInvoices(invoices) {
      const ids = invoices.map(i => (typeof i === 'object' ? i.id : i))

      return $platformApi('/billing/invoices/bulk-void', {
        method: 'POST',
        body: { invoice_ids: ids },
      })
    },

    async bulkRetryInvoices(invoices) {
      const ids = invoices.map(i => (typeof i === 'object' ? i.id : i))

      return $platformApi('/billing/invoices/bulk-retry', {
        method: 'POST',
        body: { invoice_ids: ids },
      })
    },
  },
})
