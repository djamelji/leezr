import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformSupportStore = defineStore('platformSupport', {
  state: () => ({
    _tickets: [],
    _currentTicket: null,
    _messages: [],
    _metrics: null,
    _loading: false,
    _pagination: { current_page: 1, last_page: 1, total: 0 },
  }),

  getters: {
    tickets: s => s._tickets,
    currentTicket: s => s._currentTicket,
    messages: s => s._messages,
    metrics: s => s._metrics,
    loading: s => s._loading,
    pagination: s => s._pagination,
  },

  actions: {
    async fetchMetrics() {
      this._metrics = await $platformApi('/support/metrics')
    },

    async fetchTickets(params = {}) {
      this._loading = true
      try {
        const query = new URLSearchParams(params).toString()
        const data = await $platformApi(`/support/tickets?${query}`)

        this._tickets = data.data
        this._pagination = {
          current_page: data.current_page,
          last_page: data.last_page,
          total: data.total,
        }
      }
      finally {
        this._loading = false
      }
    },

    async fetchTicket(id) {
      this._loading = true
      try {
        this._currentTicket = await $platformApi(`/support/tickets/${id}`)
      }
      finally {
        this._loading = false
      }
    },

    async fetchMessages(ticketId) {
      this._messages = await $platformApi(`/support/tickets/${ticketId}/messages`)
    },

    async assignTicket(ticketId, platformUserId = null) {
      const data = await $platformApi(`/support/tickets/${ticketId}/assign`, {
        method: 'PUT',
        body: { platform_user_id: platformUserId },
      })

      this._currentTicket = data

      return data
    },

    async resolveTicket(ticketId) {
      const data = await $platformApi(`/support/tickets/${ticketId}/resolve`, {
        method: 'PUT',
      })

      this._currentTicket = data

      return data
    },

    async closeTicket(ticketId) {
      const data = await $platformApi(`/support/tickets/${ticketId}/close`, {
        method: 'PUT',
      })

      this._currentTicket = data

      return data
    },

    async updatePriority(ticketId, priority) {
      const data = await $platformApi(`/support/tickets/${ticketId}/priority`, {
        method: 'PUT',
        body: { priority },
      })

      this._currentTicket = data

      return data
    },

    async sendMessage(ticketId, body) {
      const message = await $platformApi(`/support/tickets/${ticketId}/messages`, {
        method: 'POST',
        body: { body },
      })

      this._messages.push(message)

      return message
    },

    async sendInternalNote(ticketId, body) {
      const message = await $platformApi(`/support/tickets/${ticketId}/internal-notes`, {
        method: 'POST',
        body: { body },
      })

      this._messages.push(message)

      return message
    },
  },
})
