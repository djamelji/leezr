import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useDeliveryStore = defineStore('delivery', {
  state: () => ({
    _deliveries: [],
    _currentDelivery: null,
    _pagination: {
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 0,
    },
    _loaded: false,
  }),

  getters: {
    deliveries: state => state._deliveries,
    currentDelivery: state => state._currentDelivery,
    pagination: state => state._pagination,
  },

  actions: {
    async fetchDeliveries(params = {}) {
      const data = await $api('/my-deliveries', {
        params: {
          page: params.page || 1,
          per_page: params.per_page || 15,
          status: params.status || undefined,
        },
      })

      this._deliveries = data.data
      this._pagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
      }
      this._loaded = true

      return data
    },

    async fetchDelivery(id) {
      const data = await $api(`/my-deliveries/${id}`)

      this._currentDelivery = data.shipment

      return data.shipment
    },

    async updateStatus(id, status) {
      const data = await $api(`/my-deliveries/${id}/status`, {
        method: 'PUT',
        body: { status },
      })

      this._currentDelivery = data.shipment

      // Update in list if present
      const index = this._deliveries.findIndex(s => s.id === id)
      if (index !== -1) {
        this._deliveries[index] = data.shipment
      }

      return data.shipment
    },

    reset() {
      this._deliveries = []
      this._currentDelivery = null
      this._pagination = { current_page: 1, last_page: 1, per_page: 15, total: 0 }
      this._loaded = false
    },
  },
})
