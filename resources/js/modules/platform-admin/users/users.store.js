import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformUsersStore = defineStore('platformUsers', {
  state: () => ({
    _platformUsers: [],
    _platformUsersPagination: { current_page: 1, last_page: 1, total: 0 },
    _companyUsers: [],
    _companyUsersPagination: { current_page: 1, last_page: 1, total: 0 },
  }),

  getters: {
    platformUsers: state => state._platformUsers,
    platformUsersPagination: state => state._platformUsersPagination,
    companyUsers: state => state._companyUsers,
    companyUsersPagination: state => state._companyUsersPagination,
  },

  actions: {
    // ─── Platform Users (CRUD) ──────────────────────────
    async fetchPlatformUsers(page = 1) {
      const data = await $platformApi('/platform-users', { params: { page } })

      this._platformUsers = data.data
      this._platformUsersPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },

    async createPlatformUser(payload) {
      const data = await $platformApi('/platform-users', {
        method: 'POST',
        body: payload,
      })

      this._platformUsers.unshift(data.user)

      return data
    },

    async updatePlatformUser(id, payload) {
      const data = await $platformApi(`/platform-users/${id}`, {
        method: 'PUT',
        body: payload,
      })

      this._updatePlatformUserInList(data.user)

      return data
    },

    async deletePlatformUser(id) {
      const data = await $platformApi(`/platform-users/${id}`, { method: 'DELETE' })

      this._platformUsers = this._platformUsers.filter(u => u.id !== id)

      return data
    },

    async resetPlatformUserPassword(id) {
      const data = await $platformApi(`/platform-users/${id}/reset-password`, {
        method: 'POST',
      })

      return data
    },

    async setPlatformUserPassword(id, payload) {
      const data = await $platformApi(`/platform-users/${id}/password`, {
        method: 'PUT',
        body: payload,
      })

      this._updatePlatformUserInList(data.user)

      return data
    },

    // ─── Platform User Profile (show with dynamic fields) ──
    async fetchPlatformUserProfile(id) {
      return await $platformApi(`/platform-users/${id}`)
    },

    // ─── Company Users (read-only) ──────────────────────
    async fetchCompanyUsers(page = 1) {
      const data = await $platformApi('/company-users', { params: { page } })

      this._companyUsers = data.data
      this._companyUsersPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },

    _updatePlatformUserInList(user) {
      const idx = this._platformUsers.findIndex(u => u.id === user.id)
      if (idx !== -1)
        this._platformUsers[idx] = user
    },
  },
})
