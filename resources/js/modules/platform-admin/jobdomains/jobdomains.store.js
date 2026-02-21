import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformJobdomainsStore = defineStore('platformJobdomains', {
  state: () => ({
    _jobdomains: [],
  }),

  getters: {
    jobdomains: state => state._jobdomains,
  },

  actions: {
    async fetchJobdomains() {
      const data = await $platformApi('/jobdomains')

      this._jobdomains = data.jobdomains
    },

    async fetchJobdomain(id) {
      return await $platformApi(`/jobdomains/${id}`)
    },

    async createJobdomain(payload) {
      const data = await $platformApi('/jobdomains', {
        method: 'POST',
        body: payload,
      })

      this._jobdomains.push(data.jobdomain)

      return data
    },

    async updateJobdomain(id, payload) {
      const data = await $platformApi(`/jobdomains/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._jobdomains.findIndex(j => j.id === id)
      if (idx !== -1)
        this._jobdomains[idx] = data.jobdomain

      return data
    },

    async deleteJobdomain(id) {
      const data = await $platformApi(`/jobdomains/${id}`, { method: 'DELETE' })

      this._jobdomains = this._jobdomains.filter(j => j.id !== id)

      return data
    },
  },
})
