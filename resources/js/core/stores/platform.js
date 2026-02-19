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
    _fieldDefinitions: [],
    _fieldActivations: [],
    _jobdomains: [],
    _themeSettings: null,
    _sessionSettings: null,
    _typographySettings: null,
    _fontFamilies: [],
    _maintenanceSettings: null,
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
    fieldDefinitions: state => state._fieldDefinitions,
    fieldActivations: state => state._fieldActivations,
    jobdomains: state => state._jobdomains,
    themeSettings: state => state._themeSettings,
    sessionSettings: state => state._sessionSettings,
    typographySettings: state => state._typographySettings,
    fontFamilies: state => state._fontFamilies,
    maintenanceSettings: state => state._maintenanceSettings,
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

    // ─── Field Definitions (CRUD) ─────────────────────
    async fetchFieldDefinitions(scope = null) {
      const params = scope ? { scope } : {}
      const data = await $platformApi('/field-definitions', { params })

      this._fieldDefinitions = data.field_definitions
    },

    async createFieldDefinition(payload) {
      const data = await $platformApi('/field-definitions', {
        method: 'POST',
        body: payload,
      })

      this._fieldDefinitions.push(data.field_definition)

      return data
    },

    async updateFieldDefinition(id, payload) {
      const data = await $platformApi(`/field-definitions/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._fieldDefinitions.findIndex(f => f.id === id)
      if (idx !== -1)
        this._fieldDefinitions[idx] = data.field_definition

      return data
    },

    async deleteFieldDefinition(id) {
      const data = await $platformApi(`/field-definitions/${id}`, { method: 'DELETE' })

      this._fieldDefinitions = this._fieldDefinitions.filter(f => f.id !== id)

      return data
    },

    // ─── Field Activations (platform_user scope) ─────
    async fetchFieldActivations() {
      const data = await $platformApi('/field-activations')

      this._fieldActivations = data.field_activations
    },

    async upsertFieldActivation(payload) {
      const data = await $platformApi('/field-activations', {
        method: 'POST',
        body: payload,
      })

      const idx = this._fieldActivations.findIndex(
        a => a.field_definition_id === data.field_activation.field_definition_id,
      )

      if (idx !== -1)
        this._fieldActivations[idx] = data.field_activation
      else
        this._fieldActivations.push(data.field_activation)

      return data
    },

    // ─── Platform User Profile (show with dynamic fields) ──
    async fetchPlatformUserProfile(id) {
      return await $platformApi(`/platform-users/${id}`)
    },

    // ─── Job Domains (CRUD) ─────────────────────────────
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

    // ─── Theme Settings ────────────────────────────────
    async fetchThemeSettings() {
      const data = await $platformApi('/theme')

      this._themeSettings = data.theme
    },

    async updateThemeSettings(payload) {
      const data = await $platformApi('/theme', {
        method: 'PUT',
        body: payload,
      })

      this._themeSettings = data.theme

      return data
    },

    // ─── Session Settings ─────────────────────────────
    async fetchSessionSettings() {
      const data = await $platformApi('/session-settings')

      this._sessionSettings = data.session
    },

    async updateSessionSettings(payload) {
      const data = await $platformApi('/session-settings', {
        method: 'PUT',
        body: payload,
      })

      this._sessionSettings = data.session

      return data
    },

    // ─── Typography Settings ────────────────────────────
    async fetchTypographySettings() {
      const data = await $platformApi('/typography')

      this._typographySettings = data.typography
      this._fontFamilies = data.families
    },

    async updateTypographySettings(payload) {
      const data = await $platformApi('/typography', {
        method: 'PUT',
        body: payload,
      })

      this._typographySettings = data.typography

      return data
    },

    async createFontFamily(payload) {
      const data = await $platformApi('/font-families', {
        method: 'POST',
        body: payload,
      })

      this._fontFamilies.push(data.family)

      return data
    },

    async uploadFont(familyId, formData) {
      const data = await $platformApi(`/font-families/${familyId}/fonts`, {
        method: 'POST',
        body: formData,
      })

      const idx = this._fontFamilies.findIndex(f => f.id === familyId)
      if (idx !== -1)
        this._fontFamilies[idx] = data.family

      return data
    },

    async deleteFont(familyId, fontId) {
      const data = await $platformApi(`/font-families/${familyId}/fonts/${fontId}`, {
        method: 'DELETE',
      })

      const idx = this._fontFamilies.findIndex(f => f.id === familyId)
      if (idx !== -1)
        this._fontFamilies[idx] = data.family

      return data
    },

    async deleteFontFamily(familyId) {
      const data = await $platformApi(`/font-families/${familyId}`, {
        method: 'DELETE',
      })

      this._fontFamilies = this._fontFamilies.filter(f => f.id !== familyId)

      return data
    },

    // ─── Maintenance Settings ────────────────────────────
    async fetchMaintenanceSettings() {
      const data = await $platformApi('/maintenance-settings')

      this._maintenanceSettings = data.maintenance
    },

    async updateMaintenanceSettings(payload) {
      const data = await $platformApi('/maintenance-settings', {
        method: 'PUT',
        body: payload,
      })

      this._maintenanceSettings = data.maintenance

      return data
    },

    async fetchMyIp() {
      return await $platformApi('/maintenance/my-ip')
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
