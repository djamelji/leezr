import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformFieldsStore = defineStore('platformFields', {
  state: () => ({
    _fieldDefinitions: [],
    _fieldActivations: [],
  }),

  getters: {
    fieldDefinitions: state => state._fieldDefinitions,
    fieldActivations: state => state._fieldActivations,
  },

  actions: {
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
  },
})
