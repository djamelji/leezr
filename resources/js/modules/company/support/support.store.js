import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useSupportStore = defineStore('companySupport', {
  state: () => ({
    _tickets: [],
    _currentTicket: null,
    _messages: [],
    _loading: false,
    _pagination: { current_page: 1, last_page: 1, total: 0 },
  }),

  getters: {
    tickets: s => s._tickets,
    currentTicket: s => s._currentTicket,
    messages: s => s._messages,
    loading: s => s._loading,
    pagination: s => s._pagination,
  },

  actions: {
    async fetchTickets(params = {}) {
      this._loading = true
      try {
        const query = new URLSearchParams(params).toString()
        const data = await $api(`/support/tickets?${query}`)

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
        this._currentTicket = await $api(`/support/tickets/${id}`)
      }
      finally {
        this._loading = false
      }
    },

    async fetchMessages(ticketId) {
      const data = await $api(`/support/tickets/${ticketId}/messages`)

      this._messages = data
    },

    async createTicket(payload) {
      const data = await $api('/support/tickets', {
        method: 'POST',
        body: payload,
      })

      this._tickets.unshift(data)

      return data
    },

    async sendMessage(ticketId, body) {
      const message = await $api(`/support/tickets/${ticketId}/messages`, {
        method: 'POST',
        body: { body },
      })

      this._messages.push(message)

      return message
    },
  },
})
