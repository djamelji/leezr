import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useShipmentStore = defineStore('shipment', {
  state: () => ({
    _shipments: [],
    _currentShipment: null,
    _pagination: {
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 0,
    },
    _loaded: false,
  }),

  getters: {
    shipments: state => state._shipments,
    currentShipment: state => state._currentShipment,
    pagination: state => state._pagination,
  },

  actions: {
    async fetchShipments(params = {}) {
      const data = await $api('/shipments', {
        params: {
          page: params.page || 1,
          per_page: params.per_page || 15,
          status: params.status || undefined,
          search: params.search || undefined,
        },
      })

      this._shipments = data.data
      this._pagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
      }
      this._loaded = true

      return data
    },

    async fetchShipment(id) {
      const data = await $api(`/shipments/${id}`)

      this._currentShipment = data.shipment

      return data.shipment
    },

    async createShipment(payload) {
      const data = await $api('/shipments', {
        method: 'POST',
        body: payload,
      })

      return data.shipment
    },

    async assignShipment(id, userId) {
      const data = await $api(`/shipments/${id}/assign`, {
        method: 'POST',
        body: { user_id: userId },
      })

      this._currentShipment = data.shipment

      // Update in list if present
      const index = this._shipments.findIndex(s => s.id === id)
      if (index !== -1) {
        this._shipments[index] = data.shipment
      }

      return data.shipment
    },

    async changeStatus(id, status) {
      const data = await $api(`/shipments/${id}/status`, {
        method: 'PUT',
        body: { status },
      })

      this._currentShipment = data.shipment

      // Update in list if present
      const index = this._shipments.findIndex(s => s.id === id)
      if (index !== -1) {
        this._shipments[index] = data.shipment
      }

      return data.shipment
    },

    reset() {
      this._shipments = []
      this._currentShipment = null
      this._pagination = { current_page: 1, last_page: 1, per_page: 15, total: 0 }
      this._loaded = false
    },
  },
})
