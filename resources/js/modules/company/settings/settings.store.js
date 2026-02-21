import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useCompanySettingsStore = defineStore('companySettings', {
  state: () => ({
    _company: null,
    _roles: [],
    _permissionCatalog: [],
    _permissionModules: [],
  }),

  getters: {
    company: state => state._company,
    roles: state => state._roles,
    permissionCatalog: state => state._permissionCatalog,
    permissionModules: state => state._permissionModules,
  },

  actions: {
    async fetchCompany() {
      const data = await $api('/company')

      this._company = data

      return data
    },

    async updateCompany(payload) {
      const data = await $api('/company', {
        method: 'PUT',
        body: payload,
      })

      this._company = data

      return data
    },

    // ─── Company Roles (CRUD) ──────────────────────────
    async fetchCompanyRoles() {
      const data = await $api('/company/roles')

      this._roles = data.roles
    },

    async fetchPermissionCatalog() {
      const data = await $api('/company/permissions')

      this._permissionCatalog = data.permissions
      this._permissionModules = data.modules || []
    },

    async createCompanyRole(payload) {
      const data = await $api('/company/roles', {
        method: 'POST',
        body: payload,
      })

      this._roles.push(data.role)

      return data
    },

    async updateCompanyRole(id, payload) {
      const data = await $api(`/company/roles/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._roles.findIndex(r => r.id === id)
      if (idx !== -1)
        this._roles[idx] = data.role

      return data
    },

    async deleteCompanyRole(id) {
      const data = await $api(`/company/roles/${id}`, { method: 'DELETE' })

      this._roles = this._roles.filter(r => r.id !== id)

      return data
    },
  },
})
