import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformBillingMetricsStore = defineStore('platformBillingMetrics', {
  state: () => ({
    // Billing widgets (D4e)
    _widgets: [],
    _widgetData: {},
    _widgetsLoading: false,
    _widgetLoading: {},
    _widgetsPeriod: '30d',

    // Billing metrics (ADR-227)
    _metrics: null,
    _metricsLoading: false,

    // Recovery status (ADR-236)
    _recoveryStatus: null,
    _recoveryLoading: false,

    // Dead letters (ADR-345)
    _deadLetters: [],
    _deadLettersPagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 },
    _deadLettersLoading: false,
  }),

  getters: {
    widgets: state => state._widgets,
    widgetData: state => state._widgetData,
    widgetsLoading: state => state._widgetsLoading,
    widgetLoading: state => state._widgetLoading,
    widgetsPeriod: state => state._widgetsPeriod,
    metrics: state => state._metrics,
    metricsLoading: state => state._metricsLoading,
    recoveryStatus: state => state._recoveryStatus,
    recoveryLoading: state => state._recoveryLoading,
    deadLetters: state => state._deadLetters,
    deadLettersPagination: state => state._deadLettersPagination,
    deadLettersLoading: state => state._deadLettersLoading,
  },

  actions: {
    async fetchWidgets(companyId) {
      this._widgetsLoading = true
      try {
        const data = await $platformApi('/billing/widgets', {
          params: { company_id: companyId },
        })

        this._widgets = data.widgets || []
      }
      finally {
        this._widgetsLoading = false
      }
    },

    async fetchWidget(key, companyId, period) {
      this._widgetLoading = { ...this._widgetLoading, [key]: true }
      try {
        const data = await $platformApi(`/billing/widgets/${key}`, {
          params: { company_id: companyId, period },
        })

        this._widgetData = { ...this._widgetData, [key]: data.data }
      }
      finally {
        this._widgetLoading = { ...this._widgetLoading, [key]: false }
      }
    },

    setWidgetsPeriod(period) {
      this._widgetsPeriod = period
    },

    async fetchAllWidgets(companyId) {
      await this.fetchWidgets(companyId)

      const period = this._widgetsPeriod
      await Promise.all(
        this._widgets.map(w => this.fetchWidget(w.key, companyId, period)),
      )
    },

    async fetchMetrics() {
      this._metricsLoading = true
      try {
        this._metrics = await $platformApi('/billing/metrics')
      }
      finally {
        this._metricsLoading = false
      }
    },

    async fetchRecoveryStatus() {
      this._recoveryLoading = true
      try {
        this._recoveryStatus = await $platformApi('/billing/recovery-status')
      }
      finally {
        this._recoveryLoading = false
      }
    },

    // Recovery operations (ADR-345)
    async recoverCheckouts() {
      return await $platformApi('/billing/recover-checkouts', { method: 'POST' })
    },

    async recoverWebhooks() {
      return await $platformApi('/billing/recover-webhooks', { method: 'POST' })
    },

    async replayAllDeadLetters() {
      return await $platformApi('/billing/replay-dead-letters', { method: 'POST' })
    },

    async replayDeadLetter(id) {
      return await $platformApi(`/billing/replay-dead-letters/${id}`, { method: 'POST' })
    },

    async fetchDeadLetters(page = 1) {
      this._deadLettersLoading = true
      try {
        const data = await $platformApi('/billing/dead-letters', {
          params: { page, per_page: 20 },
        })

        this._deadLetters = data.data || []
        this._deadLettersPagination = {
          current_page: data.current_page,
          per_page: data.per_page,
          total: data.total,
          last_page: data.last_page,
        }
      }
      finally {
        this._deadLettersLoading = false
      }
    },
  },
})
