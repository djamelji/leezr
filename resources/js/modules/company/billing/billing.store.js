import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useCompanyBillingStore = defineStore('companyBilling', {
  state: () => ({
    _paymentMethods: [],
    _overview: null,
    _invoices: [],
    _invoicePagination: { current_page: 1, per_page: 15, total: 0, last_page: 1 },
    _invoiceDetail: null,
    _payments: [],
    _paymentPagination: { current_page: 1, per_page: 15, total: 0, last_page: 1 },
    _subscription: null,
    _wallet: { balance: 0, currency: 'EUR', transactions: [] },
    _portalUrl: null,
  }),

  getters: {
    paymentMethods: state => state._paymentMethods,
    overview: state => state._overview,
    invoices: state => state._invoices,
    invoicePagination: state => state._invoicePagination,
    invoiceDetail: state => state._invoiceDetail,
    payments: state => state._payments,
    paymentPagination: state => state._paymentPagination,
    subscription: state => state._subscription,
    wallet: state => state._wallet,
    portalUrl: state => state._portalUrl,
  },

  actions: {
    async fetchPaymentMethods() {
      const data = await $api('/billing/payment-methods')

      this._paymentMethods = data.methods
    },

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

    async fetchPayments({ page = 1 } = {}) {
      const data = await $api(`/billing/payments?page=${page}`)

      this._payments = data.data
      this._paymentPagination = {
        current_page: data.current_page,
        per_page: data.per_page,
        total: data.total,
        last_page: data.last_page,
      }
    },

    async fetchSubscription() {
      const data = await $api('/billing/subscription')

      this._subscription = data.subscription
    },

    async fetchWallet() {
      const data = await $api('/billing/wallet')

      this._wallet = {
        balance: data.balance,
        currency: data.currency,
        transactions: data.transactions,
      }
    },

    async fetchPortalUrl() {
      const data = await $api('/billing/portal-url')

      this._portalUrl = data.url
    },
  },
})
