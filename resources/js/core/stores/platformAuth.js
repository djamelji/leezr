import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'
import { refreshCsrf } from '@/utils/csrf'
import { postBroadcast } from '@/core/runtime/broadcast'

export const usePlatformAuthStore = defineStore('platformAuth', {
  state: () => ({
    _user: useCookie('platformUserData').value || null,
    _roles: useCookie('platformRoles').value || [],
    _permissions: useCookie('platformPermissions').value || [],
    _hydrated: false,
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

    async login({ email, password }) {
      await refreshCsrf()

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
      this._hydrated = false
      postBroadcast('logout')
    },

    async fetchMe({ signal } = {}) {
      try {
        const data = await $platformApi('/me', { _authCheck: true, signal })

        this._persistUser(data.user)
        this._persistRoles(data.roles || [])
        this._persistPermissions(data.permissions || [])
        this._hydrated = true

        return data.user
      }
      catch {
        this._persistUser(null)
        this._persistRoles([])
        this._persistPermissions([])
        this._hydrated = true

        return null
      }
    },
  },
})
