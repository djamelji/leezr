import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const useBillingPolicyStore = defineStore('billingPolicy', {
  state: () => ({
    _policy: null,
  }),

  getters: {
    policy: state => state._policy,
  },

  actions: {
    async fetchPolicy() {
      const data = await $platformApi('/billing/billing-policy')

      this._policy = data.policy
    },

    async updatePolicy(payload) {
      const data = await $platformApi('/billing/billing-policy', {
        method: 'PUT',
        body: payload,
      })

      this._policy = data.policy

      return data
    },
  },
})
