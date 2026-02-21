import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformRolesStore = defineStore('platformRoles', {
  state: () => ({
    _roles: [],
    _permissionCatalog: [],
  }),

  getters: {
    roles: state => state._roles,
    permissionCatalog: state => state._permissionCatalog,
  },

  actions: {
    async fetchRoles() {
      const data = await $platformApi('/roles')

      this._roles = data.roles
    },

    async createRole(payload) {
      const data = await $platformApi('/roles', {
        method: 'POST',
        body: payload,
      })

      this._roles.push(data.role)

      return data
    },

    async updateRole(id, payload) {
      const data = await $platformApi(`/roles/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._roles.findIndex(r => r.id === id)
      if (idx !== -1)
        this._roles[idx] = data.role

      return data
    },

    async deleteRole(id) {
      const data = await $platformApi(`/roles/${id}`, { method: 'DELETE' })

      this._roles = this._roles.filter(r => r.id !== id)

      return data
    },

    async fetchPermissionCatalog() {
      const data = await $platformApi('/permissions')

      this._permissionCatalog = data.permissions
    },
  },
})
