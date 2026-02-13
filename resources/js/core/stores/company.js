import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useCompanyStore = defineStore('company', {
  state: () => ({
    _company: null,
    _members: [],
    _fieldActivations: [],
    _availableFieldDefinitions: [],
    _customFieldDefinitions: [],
  }),

  getters: {
    company: state => state._company,
    members: state => state._members,
    fieldActivations: state => state._fieldActivations,
    availableFieldDefinitions: state => state._availableFieldDefinitions,
    customFieldDefinitions: state => state._customFieldDefinitions,
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

    async fetchMembers() {
      const data = await $api('/company/members')

      this._members = data.members

      return data.members
    },

    async addMember({ email, role }) {
      const data = await $api('/company/members', {
        method: 'POST',
        body: { email, role },
      })

      this._members.push(data.member)

      return data.member
    },

    async updateMember(id, { role }) {
      const data = await $api(`/company/members/${id}`, {
        method: 'PUT',
        body: { role },
      })

      const index = this._members.findIndex(m => m.id === id)
      if (index !== -1)
        this._members[index] = data.member

      return data.member
    },

    async removeMember(id) {
      await $api(`/company/members/${id}`, {
        method: 'DELETE',
      })

      this._members = this._members.filter(m => m.id !== id)
    },

    // ─── Member Profile (show with dynamic fields) ──────
    async fetchMemberProfile(id) {
      return await $api(`/company/members/${id}`)
    },

    // ─── Field Activations (company + company_user scopes) ──
    async fetchFieldActivations() {
      const data = await $api('/company/field-activations')

      this._fieldActivations = data.field_activations
      this._availableFieldDefinitions = data.available_definitions
    },

    async upsertFieldActivation(payload) {
      const data = await $api('/company/field-activations', {
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

      // Remove from available if now activated
      this._availableFieldDefinitions = this._availableFieldDefinitions.filter(
        d => d.id !== data.field_activation.field_definition_id,
      )

      return data
    },

    // ─── Custom Field Definitions (company-owned) ──────────
    async fetchCustomFieldDefinitions() {
      const data = await $api('/company/field-definitions')

      this._customFieldDefinitions = data.custom_definitions
    },

    async createCustomFieldDefinition(payload) {
      const data = await $api('/company/field-definitions', {
        method: 'POST',
        body: payload,
      })

      this._customFieldDefinitions.push(data.field_definition)

      // Refresh activations to include the auto-activated field
      await this.fetchFieldActivations()

      return data
    },

    async updateCustomFieldDefinition(id, payload) {
      const data = await $api(`/company/field-definitions/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._customFieldDefinitions.findIndex(d => d.id === id)
      if (idx !== -1)
        this._customFieldDefinitions[idx] = data.field_definition

      return data
    },

    async deleteCustomFieldDefinition(id) {
      const data = await $api(`/company/field-definitions/${id}`, {
        method: 'DELETE',
      })

      this._customFieldDefinitions = this._customFieldDefinitions.filter(d => d.id !== id)

      // Refresh activations to remove the deleted field
      await this.fetchFieldActivations()

      return data
    },
  },
})
