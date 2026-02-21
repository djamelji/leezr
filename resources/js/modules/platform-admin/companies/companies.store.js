import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformCompaniesStore = defineStore('platformCompanies', {
  state: () => ({
    _companies: [],
    _companiesPagination: { current_page: 1, last_page: 1, total: 0 },
  }),

  getters: {
    companies: state => state._companies,
    companiesPagination: state => state._companiesPagination,
  },

  actions: {
    async fetchCompanies(page = 1) {
      const data = await $platformApi('/companies', { params: { page } })

      this._companies = data.data
      this._companiesPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },

    async suspendCompany(id) {
      const data = await $platformApi(`/companies/${id}/suspend`, { method: 'PUT' })

      this._updateCompanyInList(data.company)

      return data
    },

    async reactivateCompany(id) {
      const data = await $platformApi(`/companies/${id}/reactivate`, { method: 'PUT' })

      this._updateCompanyInList(data.company)

      return data
    },

    _updateCompanyInList(company) {
      const idx = this._companies.findIndex(c => c.id === company.id)
      if (idx !== -1)
        this._companies[idx] = company
    },
  },
})
