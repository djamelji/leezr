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
  }),

  getters: {
    providers: state => state._providers,
    config: state => state._config,
    policies: state => state._policies,
    subscriptions: state => state._subscriptions,
    subscriptionsPagination: state => state._subscriptionsPagination,
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
  },
})
