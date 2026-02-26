import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformPaymentsStore = defineStore('platformPayments', {
  state: () => ({
    _providers: [],
    _config: { driver: 'null', config: {} },
    _policies: {
      payment_required: false,
      admin_approval_required: true,
      annual_only: false,
      currency: 'usd',
      vat_enabled: false,
      vat_rate: 0,
    },
    _subscriptions: [],
    _subscriptionsPagination: { current_page: 1, last_page: 1, total: 0 },
    _paymentModules: [],
    _paymentRules: [],
    _previewMethods: [],
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
  },
})
