import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useJobdomainStore = defineStore('jobdomain', {
  state: () => ({
    _assigned: false,
    _jobdomain: null,
    _profile: null,
    _available: [],
    _allowCustomFields: false,
    _loaded: false,
  }),

  getters: {
    assigned: state => state._assigned,
    jobdomain: state => state._jobdomain,
    profile: state => state._profile,
    available: state => state._available,
    allowCustomFields: state => state._allowCustomFields,

    /**
     * Landing route from the jobdomain profile. Fallback to '/'.
     */
    landingRoute: state => state._profile?.landing_route || '/',

    /**
     * Nav profile key from the jobdomain profile.
     */
    navProfile: state => state._profile?.nav_profile || null,
  },

  actions: {
    async fetchJobdomain() {
      const data = await $api('/company/jobdomain')

      this._assigned = data.assigned
      this._jobdomain = data.jobdomain
      this._profile = data.profile
      this._available = data.available || []
      this._allowCustomFields = data.allow_custom_fields || false
      this._loaded = true

      return data
    },

    async setJobdomain(key) {
      const data = await $api('/company/jobdomain', {
        method: 'PUT',
        body: { key },
      })

      this._assigned = data.assigned
      this._jobdomain = data.jobdomain
      this._profile = data.profile
      this._allowCustomFields = data.allow_custom_fields || false

      return data
    },

    reset() {
      this._assigned = false
      this._jobdomain = null
      this._profile = null
      this._available = []
      this._allowCustomFields = false
      this._loaded = false
    },
  },
})
