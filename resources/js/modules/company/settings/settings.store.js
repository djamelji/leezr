import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { cacheRemove } from '@/core/runtime/cache'
import { useAuthStore } from '@/core/stores/auth'
import { useNavStore } from '@/core/stores/nav'

export const useCompanySettingsStore = defineStore('companySettings', {
  state: () => ({
    _company: null,
    _roles: [],
    _permissionCatalog: [],
    _permissionModules: [],
    _marketInfo: null,
    _companyDocuments: [],
    _documentActivations: { company_user_documents: [], company_documents: [] },
  }),

  getters: {
    company: state => state._company,
    roles: state => state._roles,
    permissionCatalog: state => state._permissionCatalog,
    permissionModules: state => state._permissionModules,
    marketInfo: state => state._marketInfo,
    companyDocuments: state => state._companyDocuments,
    documentActivations: state => state._documentActivations,
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
    async fetchCompanyRoles({ silent = false } = {}) {
      const data = await $api('/company/roles', { _silent403: silent })

      this._roles = data.roles
    },

    async fetchPermissionCatalog({ silent = false } = {}) {
      const data = await $api('/company/permissions', { _silent403: silent })

      this._permissionCatalog = data.permissions
      this._permissionModules = data.modules || []
    },

    async createCompanyRole(payload) {
      const data = await $api('/company/roles', {
        method: 'POST',
        body: payload,
      })

      this._roles.push(data.role)
      this._refreshNavAfterRoleChange()

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

      this._refreshNavAfterRoleChange()

      return data
    },

    async deleteCompanyRole(id) {
      const data = await $api(`/company/roles/${id}`, { method: 'DELETE' })

      this._roles = this._roles.filter(r => r.id !== id)
      this._refreshNavAfterRoleChange()

      return data
    },

    /**
     * Refresh nav + auth after role CRUD so permission changes
     * are reflected in the UI without logout.
     * Clears sessionStorage cache first so the next boot/refresh
     * won't serve stale data.
     */
    _refreshNavAfterRoleChange() {
      cacheRemove('features:nav')
      cacheRemove('auth:companies')

      const navStore = useNavStore()
      const authStore = useAuthStore()

      Promise.all([
        navStore.fetchCompanyNav(),
        authStore.fetchMyCompanies(),
      ]).catch(() => {})
    },

    // ─── Company Documents Vault (ADR-174) ────────────
    async fetchCompanyDocuments() {
      try {
        const data = await $api('/company/documents')

        this._companyDocuments = data.documents || []
      }
      catch {
        this._companyDocuments = []
      }
    },

    // ─── Document Activation Catalog (ADR-175) ────────
    async fetchDocumentActivations() {
      try {
        const data = await $api('/company/document-activations')

        this._documentActivations = data
      }
      catch {
        this._documentActivations = { company_user_documents: [], company_documents: [] }
      }
    },

    // ─── Custom Document Types (ADR-180) ────────────────
    async createCustomDocumentType(payload) {
      return await $api('/company/document-types/custom', { method: 'POST', body: payload })
    },

    async archiveCustomDocumentType(code) {
      return await $api(`/company/document-types/custom/${code}/archive`, { method: 'PUT' })
    },

    async deleteCustomDocumentType(code) {
      return await $api(`/company/document-types/custom/${code}`, { method: 'DELETE' })
    },

    // ─── Legal Structure (ADR-104) ─────────────────────
    async fetchLegalStructure() {
      const data = await $api('/company/legal-structure')

      this._marketInfo = data

      return data
    },

    async updateLegalStatus(legalStatusKey) {
      const data = await $api('/company/legal-structure', {
        method: 'PUT',
        body: { legal_status_key: legalStatusKey },
      })

      // Refresh to sync
      await this.fetchLegalStructure()

      return data
    },
  },
})
