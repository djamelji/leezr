import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformAuthStore = defineStore('platformAuth', {
  state: () => ({
    _user: useCookie('platformUserData').value || null,
    _roles: useCookie('platformRoles').value || [],
    _permissions: useCookie('platformPermissions').value || [],
  }),

  getters: {
    user: state => state._user,
    isLoggedIn: state => !!state._user,
    roles: state => state._roles,
    permissions: state => state._permissions,
    isSuperAdmin: state => Array.isArray(state._roles) && state._roles.includes('super_admin'),
  },

  actions: {
    _persistUser(user) {
      this._user = user
      useCookie('platformUserData').value = user
    },

    _persistRoles(roles) {
      this._roles = roles
      useCookie('platformRoles').value = roles
    },

    _persistPermissions(permissions) {
      this._permissions = permissions
      useCookie('platformPermissions').value = permissions
    },

    hasPermission(key) {
      if (this.isSuperAdmin) return true

      return Array.isArray(this._permissions) && this._permissions.includes(key)
    },

    async fetchCsrf() {
      await fetch('/sanctum/csrf-cookie', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })
    },

    async login({ email, password }) {
      await this.fetchCsrf()

      const data = await $platformApi('/login', {
        method: 'POST',
        body: { email, password },
      })

      this._persistUser(data.user)
      this._persistRoles(data.roles || [])
      this._persistPermissions(data.permissions || [])

      return data
    },

    async logout() {
      try {
        await $platformApi('/logout', { method: 'POST' })
      }
      catch {
        // Ignore errors on logout
      }

      this._persistUser(null)
      this._persistRoles([])
      this._persistPermissions([])
    },

    async fetchMe() {
      try {
        const data = await $platformApi('/me')

        this._persistUser(data.user)
        this._persistRoles(data.roles || [])
        this._persistPermissions(data.permissions || [])

        return data.user
      }
      catch {
        this._persistUser(null)
        this._persistRoles([])
        this._persistPermissions([])

        return null
      }
    },
  },
})
