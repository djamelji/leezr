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
  },
})
