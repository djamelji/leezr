import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

// W-SIGNATURE-1 (ADR-651) — module Signatures électroniques RH : demandes + assistant + paramètres.
export const useSignatureStore = defineStore('workforceSignature', {
  state: () => ({ _items: [], _meta: {}, _loading: false }),
  getters: {
    items: s => s._items,
    meta: s => s._meta,
    loading: s => s._loading,
  },
  actions: {
    async fetchList(filters = {}) {
      this._loading = true
      try {
        const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== null && v !== undefined && v !== '')).toString()
        const data = await $api(`/workforce/signature${qs ? `?${qs}` : ''}`)
        this._items = data.data ?? []
        this._meta = data.meta ?? {}

        return this._items
      } finally { this._loading = false }
    },
    async fetchOptions() {
      return await $api('/workforce/signature/options')
    },
    async fetchByEmployee(employeeId) {
      const data = await $api(`/workforce/signature/by-employee/${employeeId}`)

      return data.data ?? []
    },
    async fetchEligibleDocuments() {
      const data = await $api('/workforce/signature/eligible-documents')

      return data.data ?? []
    },
    async fetchOne(id) {
      return await $api(`/workforce/signature/${id}`)
    },
    async fetchEvidence(id) {
      return await $api(`/workforce/signature/${id}/evidence`)
    },
    async verifySignature(id) {
      return await $api(`/workforce/signature/${id}/verify`)
    },
    async create(payload) {
      return await $api('/workforce/signature', { method: 'POST', body: payload })
    },
    async cancel(id, reason) {
      return await $api(`/workforce/signature/${id}/cancel`, { method: 'POST', body: { reason } })
    },
    async remind(id) {
      return await $api(`/workforce/signature/${id}/remind`, { method: 'POST' })
    },
    async getSettings() {
      return await $api('/workforce/signature/settings')
    },
    async updateSettings(payload) {
      return await $api('/workforce/signature/settings', { method: 'PUT', body: payload })
    },
    // Téléchargement via blob ($api ajoute l'en-tête X-Company-Id — un <a href> ne le ferait pas).
    async downloadSigned(id) {
      return await $api(`/workforce/signature/${id}/download`, { responseType: 'blob' })
    },
    // W-SIGNATURE-12 — supervision opérationnelle + registre des certificats (lecture seule, gated).
    async fetchSupervision() {
      return await $api('/workforce/signature/supervision')
    },
    async fetchCertificates() {
      return await $api('/workforce/signature/certificates')
    },
  },
})
