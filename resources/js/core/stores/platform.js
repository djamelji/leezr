import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformStore = defineStore('platform', {
  state: () => ({
    _companies: [],
    _companiesPagination: { current_page: 1, last_page: 1, total: 0 },
    _platformUsers: [],
    _platformUsersPagination: { current_page: 1, last_page: 1, total: 0 },
    _companyUsers: [],
    _companyUsersPagination: { current_page: 1, last_page: 1, total: 0 },
    _roles: [],
    _permissionCatalog: [],
    _modules: [],
  }),

  getters: {
    companies: state => state._companies,
    companiesPagination: state => state._companiesPagination,
    platformUsers: state => state._platformUsers,
    platformUsersPagination: state => state._platformUsersPagination,
    companyUsers: state => state._companyUsers,
    companyUsersPagination: state => state._companyUsersPagination,
    roles: state => state._roles,
    permissionCatalog: state => state._permissionCatalog,
    modules: state => state._modules,
  },

  actions: {
    // ─── Companies ──────────────────────────────────────
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

    // ─── Roles (CRUD) ───────────────────────────────────
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

    // ─── Permission Catalog (read-only, for CRUD dropdowns) ──
    async fetchPermissionCatalog() {
      const data = await $platformApi('/permissions')

      this._permissionCatalog = data.permissions
    },

    // ─── Modules ────────────────────────────────────────
    async fetchModules() {
      const data = await $platformApi('/modules')

      this._modules = data.modules
    },

    async toggleModule(key) {
      const data = await $platformApi(`/modules/${key}/toggle`, { method: 'PUT' })

      const idx = this._modules.findIndex(m => m.key === key)
      if (idx !== -1)
        this._modules[idx] = data.module

      return data
    },

    // ─── Helpers ────────────────────────────────────────
    _updateCompanyInList(company) {
      const idx = this._companies.findIndex(c => c.id === company.id)
      if (idx !== -1)
        this._companies[idx] = company
    },

    _updatePlatformUserInList(user) {
      const idx = this._platformUsers.findIndex(u => u.id === user.id)
      if (idx !== -1)
        this._platformUsers[idx] = user
    },
  },
})
