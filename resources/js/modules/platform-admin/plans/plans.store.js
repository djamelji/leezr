import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformPlansStore = defineStore('platformPlans', {
  state: () => ({
    _plans: [],
    _currentPlan: null,
    _planCompanies: [],
    _planCompaniesPagination: { current_page: 1, last_page: 1, total: 0 },
  }),

  getters: {
    plans: state => state._plans,
    currentPlan: state => state._currentPlan,
    planCompanies: state => state._planCompanies,
    planCompaniesPagination: state => state._planCompaniesPagination,
  },

  actions: {
    async fetchPlans() {
      this._plans = await $platformApi('/plans')
    },

    async fetchPlan(key, companiesPage = 1) {
      const data = await $platformApi(`/plans/${key}`, {
        params: { companies_page: companiesPage },
      })

      this._currentPlan = data.plan
      this._planCompanies = data.companies.data
      this._planCompaniesPagination = {
        current_page: data.companies.current_page,
        last_page: data.companies.last_page,
        total: data.companies.total,
      }

      return data
    },

    async createPlan(payload) {
      const data = await $platformApi('/plans', {
        method: 'POST',
        body: payload,
      })

      this._plans.push(data.plan)

      return data
    },

    async updatePlan(id, payload) {
      const data = await $platformApi(`/plans/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._plans.findIndex(p => p.id === id)
      if (idx !== -1)
        this._plans[idx] = data.plan

      if (this._currentPlan?.id === id)
        this._currentPlan = data.plan

      return data
    },

    async toggleActive(id) {
      const data = await $platformApi(`/plans/${id}/toggle-active`, {
        method: 'PUT',
      })

      const idx = this._plans.findIndex(p => p.id === id)
      if (idx !== -1)
        this._plans[idx] = data.plan

      if (this._currentPlan?.id === id)
        this._currentPlan = data.plan

      return data
    },
  },
})
