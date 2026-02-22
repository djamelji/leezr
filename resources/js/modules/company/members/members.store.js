import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

// Centralized owner-detection: sole location that reads the role field.
// All consumers check _isProtected instead of comparing role strings.
const hydrateMember = m => ({ ...m, _isProtected: m.role === 'owner' })

export const useMembersStore = defineStore('companyMembers', {
  state: () => ({
    _members: [],
    _fieldActivations: [],
    _availableFieldDefinitions: [],
    _customFieldDefinitions: [],
  }),

  getters: {
    members: state => state._members,
    fieldActivations: state => state._fieldActivations,
    availableFieldDefinitions: state => state._availableFieldDefinitions,
    customFieldDefinitions: state => state._customFieldDefinitions,
  },

  actions: {
    async fetchMembers() {
      const data = await $api('/company/members')

      this._members = data.members.map(hydrateMember)

      return this._members
    },

    async addMember({ first_name, last_name, email, company_role_id }) {
      const data = await $api('/company/members', {
        method: 'POST',
        body: { first_name, last_name, email, company_role_id },
      })

      const member = hydrateMember(data.member)

      this._members.push(member)

      return member
    },

    async updateMember(id, { company_role_id }) {
      const data = await $api(`/company/members/${id}`, {
        method: 'PUT',
        body: { company_role_id },
      })

      const index = this._members.findIndex(m => m.id === id)
      if (index !== -1)
        this._members[index] = { ...this._members[index], company_role: data.member.company_role }

      return data.member
    },

    async updateMemberProfile(id, payload) {
      const data = await $api(`/company/members/${id}`, {
        method: 'PUT',
        body: payload,
      })

      return data
    },

    async removeMember(id) {
      await $api(`/company/members/${id}`, {
        method: 'DELETE',
      })

      this._members = this._members.filter(m => m.id !== id)
    },

    // ─── Member Profile (show with dynamic fields) ──────
    async fetchMemberProfile(id) {
      const data = await $api(`/company/members/${id}`)

      data.member = hydrateMember(data.member)

      return data
    },

    // ─── Member Credentials (admin-triggered) ──────
    async resetMemberPassword(id) {
      return await $api(`/company/members/${id}/reset-password`, { method: 'POST' })
    },

    async setMemberPassword(id, payload) {
      return await $api(`/company/members/${id}/password`, { method: 'PUT', body: payload })
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
