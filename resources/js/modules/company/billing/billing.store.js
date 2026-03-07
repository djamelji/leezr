import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useCompanyBillingStore = defineStore('companyBilling', {
  state: () => ({
    _overview: null,
    _invoices: [],
    _invoicePagination: { current_page: 1, per_page: 15, total: 0, last_page: 1 },
    _invoiceDetail: null,
    _subscription: null,
    _savedCards: [],
    _setupIntent: null,
    _nextInvoicePreview: null,
    _planChangePreview: null,
    _outstandingInvoices: [],
    _outstandingWallet: { balance: 0, currency: 'EUR' },
  }),

  getters: {
    overview: state => state._overview,
    nextInvoicePreview: state => state._nextInvoicePreview,
    planChangePreview: state => state._planChangePreview,
    invoices: state => state._invoices,
    invoicePagination: state => state._invoicePagination,
    invoiceDetail: state => state._invoiceDetail,
    subscription: state => state._subscription,
    savedCards: state => state._savedCards,
    setupIntent: state => state._setupIntent,
    outstandingInvoices: state => state._outstandingInvoices,
    outstandingWallet: state => state._outstandingWallet,
  },

  actions: {
    async fetchOverview() {
      const data = await $api('/billing/overview')

      this._overview = data
    },

    async fetchInvoices({ page = 1, status, from, to } = {}) {
      const params = new URLSearchParams()

      params.set('page', page)
      if (status) params.set('status', status)
      if (from) params.set('from', from)
      if (to) params.set('to', to)

      const data = await $api(`/billing/invoices?${params}`)

      this._invoices = data.data
      this._invoicePagination = {
        current_page: data.current_page,
        per_page: data.per_page,
        total: data.total,
        last_page: data.last_page,
      }
    },

    async fetchInvoiceDetail(id) {
      const data = await $api(`/billing/invoices/${id}`)

      this._invoiceDetail = data.invoice
    },

    async fetchSubscription() {
      const data = await $api('/billing/subscription')

      this._subscription = data.subscription
    },

    async fetchSavedCards() {
      const data = await $api('/billing/saved-cards')

      this._savedCards = data.cards
    },

    async createSetupIntent(method = 'card') {
      const data = await $api('/billing/setup-intent', { method: 'POST', body: { method } })

      this._setupIntent = data

      return data
    },

    async confirmSetupIntent(paymentMethodId) {
      const data = await $api('/billing/confirm-setup-intent', {
        method: 'POST',
        body: { payment_method_id: paymentMethodId },
      })

      // Add card to local list (or update if duplicate)
      if (data.card) {
        if (data.duplicate) {
          // Already exists — don't add
        }
        else {
          // Unset old defaults locally, then add
          this._savedCards = this._savedCards.map(c => ({ ...c, is_default: false }))
          this._savedCards.push(data.card)
        }
      }

      return data
    },

    async deleteSavedCard(id) {
      await $api(`/billing/saved-cards/${id}`, { method: 'DELETE' })
      this._savedCards = this._savedCards.filter(c => c.id !== id)
    },

    async setDefaultCard(id) {
      await $api(`/billing/saved-cards/${id}/default`, { method: 'PUT' })
      this._savedCards = this._savedCards.map(c => ({ ...c, is_default: c.id === id }))
    },

    async retryInvoice(id) {
      const data = await $api(`/billing/invoices/${id}/retry`, { method: 'POST' })

      return data
    },

    async setBillingDay(day) {
      await $api('/billing/subscription/billing-day', { method: 'PUT', body: { billing_anchor_day: day } })
    },

    async fetchNextInvoicePreview() {
      const data = await $api('/billing/next-invoice-preview')

      this._nextInvoicePreview = data.preview

      return data.preview
    },

    async fetchPlanChangePreview(toPlanKey, toInterval = 'monthly') {
      const params = new URLSearchParams({ to_plan_key: toPlanKey, to_interval: toInterval })
      const data = await $api(`/billing/plan-change-preview?${params}`)

      this._planChangePreview = data.preview

      return data.preview
    },

    clearPlanChangePreview() {
      this._planChangePreview = null
    },

    async cancelScheduledPlanChange() {
      await $api('/billing/plan-change', { method: 'DELETE' })
      await this.fetchSubscription()
    },

    // ── Batch payment (ADR-257) ──
    async fetchOutstandingInvoices() {
      const data = await $api('/billing/invoices/outstanding')

      this._outstandingInvoices = data.invoices
      this._outstandingWallet = { balance: data.wallet_balance, currency: data.currency }

      return data
    },

    async createBatchPayIntent(invoiceIds, useWallet = true) {
      return await $api('/billing/invoices/pay', {
        method: 'POST',
        body: { invoice_ids: invoiceIds, use_wallet: useWallet },
      })
    },

    async confirmBatchPayment(paymentIntentId, saveCard = false) {
      return await $api('/billing/invoices/pay/confirm', {
        method: 'POST',
        body: { payment_intent_id: paymentIntentId, save_card: saveCard },
      })
    },

    async cancelSubscription() {
      const idempotencyKey = `cancel-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
      const data = await $api('/billing/subscription/cancel', {
        method: 'PUT',
        body: { idempotency_key: idempotencyKey },
      })

      // Update local subscription state
      if (data.subscription)
        this._subscription = data.subscription

      return data
    },
  },
})
