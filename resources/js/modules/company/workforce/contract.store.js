import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

// W-CONTRACT-1 (ADR-647) — module Contrats de travail : bibliothèque de modèles + génération.
export const useContractStore = defineStore('workforceContract', {
  state: () => ({ _templates: [], _loading: false }),
  getters: {
    templates: s => s._templates,
    loading: s => s._loading,
  },
  actions: {
    async fetchTemplates({ contractType } = {}) {
      this._loading = true
      try {
        const qs = contractType ? `?contract_type=${encodeURIComponent(contractType)}` : ''
        const data = await $api(`/workforce/contract/templates${qs}`)
        this._templates = data.data ?? []

        return this._templates
      } finally { this._loading = false }
    },
    async fetchTemplate(id) {
      return await $api(`/workforce/contract/templates/${id}`)
    },
    async fetchVariables() {
      const data = await $api('/workforce/contract/templates/variables')

      return data.data ?? []
    },
    async duplicate(id) {
      return await $api(`/workforce/contract/templates/${id}/duplicate`, { method: 'POST' })
    },
    async createVersion(id) {
      return await $api(`/workforce/contract/templates/${id}/version`, { method: 'POST' })
    },
    async updateTemplate(id, payload) {
      return await $api(`/workforce/contract/templates/${id}`, { method: 'PUT', body: payload })
    },
    async publish(id) {
      return await $api(`/workforce/contract/templates/${id}/publish`, { method: 'POST' })
    },
    async archive(id) {
      return await $api(`/workforce/contract/templates/${id}/archive`, { method: 'POST' })
    },
    async preview({ templateId, employeeId }) {
      return await $api('/workforce/contract/preview', { method: 'POST', body: { template_id: templateId, employee_id: employeeId } })
    },
    async generate({ templateCode, employeeId }) {
      return await $api('/workforce/contract/generate', { method: 'POST', body: { template_code: templateCode, employee_id: employeeId } })
    },
  },
})
