import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useCompanyBillingStore = defineStore('companyBilling', {
  state: () => ({
    _paymentMethods: [],
    _invoices: [],
    _payments: [],
    _subscription: null,
    _portalUrl: null,
  }),

  getters: {
    paymentMethods: state => state._paymentMethods,
    invoices: state => state._invoices,
    payments: state => state._payments,
    subscription: state => state._subscription,
    portalUrl: state => state._portalUrl,
  },

  actions: {
    async fetchPaymentMethods() {
      const data = await $api('/billing/payment-methods')

      this._paymentMethods = data.methods
    },

    async fetchInvoices() {
      const data = await $api('/billing/invoices')

      this._invoices = data.invoices
    },

    async fetchPayments() {
      const data = await $api('/billing/payments')

      this._payments = data.payments
    },

    async fetchSubscription() {
      const data = await $api('/billing/subscription')

      this._subscription = data.subscription
    },

    async fetchPortalUrl() {
      const data = await $api('/billing/portal-url')

      this._portalUrl = data.url
    },
  },
})
