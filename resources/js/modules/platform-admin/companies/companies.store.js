import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformCompaniesStore = defineStore('platformCompanies', {
  state: () => ({
    _companies: [],
    _companiesPagination: { current_page: 1, last_page: 1, total: 0 },
    _stats: { total_active: 0, total_suspended: 0, total: 0, total_mrr: 0, at_risk_count: 0 },
    _plans: [],
  }),

  getters: {
    companies: state => state._companies,
    companiesPagination: state => state._companiesPagination,
    stats: state => state._stats,
    plans: state => state._plans,
  },

  actions: {
    async fetchCompanies(page = 1, filters = {}) {
      const params = { page, ...filters }
      const data = await $platformApi('/companies', { params })

      this._companies = data.data
      this._companiesPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
      if (data.stats)
        this._stats = data.stats
    },

    async suspendCompany(id) {
      const data = await $platformApi(`/companies/${id}/suspend`, { method: 'PUT' })

      this._updateCompanyInList(data.company)

      return data
    },

    async reactivateCompany(id) {
      const data = await $platformApi(`/companies/${id}/reactivate`, { method: 'PUT' })

      this._updateCompanyInList(data.company)

      return data
    },

    async fetchPlans() {
      this._plans = await $platformApi('/plans')
    },

    async updateCompanyPlan(id, planKey, interval = 'monthly') {
      const data = await $platformApi(`/companies/${id}/plan`, {
        method: 'PUT',
        body: { plan_key: planKey, interval },
      })

      this._updateCompanyInList(data.company)

      return data
    },

    async fetchPlanChangePreview(id, planKey, interval = 'monthly') {
      return await $platformApi(`/companies/${id}/plan-preview`, {
        params: { plan_key: planKey, interval },
      })
    },

    async adjustWallet(id, payload) {
      return await $platformApi(`/companies/${id}/wallet`, {
        method: 'POST',
        body: payload,
      })
    },

    async fetchCompanyProfile(id) {
      return await $platformApi(`/companies/${id}`)
    },

    async updateCompanyProfile(id, payload) {
      return await $platformApi(`/companies/${id}`, { method: 'PUT', body: payload })
    },

    async fetchCompanyBilling(id) {
      return await $platformApi(`/companies/${id}/billing`)
    },

    async fetchCompanyMembers(id) {
      return await $platformApi(`/companies/${id}/members`)
    },

    async fetchCompanyActivity(id, page = 1) {
      return await $platformApi(`/companies/${id}/activity`, { params: { page } })
    },

    async enableModule(companyId, moduleKey) {
      return await $platformApi(`/companies/${companyId}/modules/${moduleKey}/enable`, { method: 'PUT' })
    },

    async disableModule(companyId, moduleKey) {
      return await $platformApi(`/companies/${companyId}/modules/${moduleKey}/disable`, { method: 'PUT' })
    },

    // ─── Payment Methods (Phase 2.3) ───────────────

    async fetchPaymentMethods(id) {
      return await $platformApi(`/companies/${id}/payment-methods`)
    },

    async setDefaultPaymentMethod(id, pmId) {
      return await $platformApi(`/companies/${id}/payment-methods/${pmId}/default`, { method: 'PUT' })
    },

    async deletePaymentMethod(id, pmId) {
      return await $platformApi(`/companies/${id}/payment-methods/${pmId}`, { method: 'DELETE' })
    },

    // ─── Invoice Actions (Phase 2.4) ────────────────

    async retryInvoicePayment(invoiceId) {
      return await $platformApi(`/billing/invoices/${invoiceId}/retry-payment`, { method: 'POST' })
    },

    async markInvoicePaidOffline(invoiceId) {
      return await $platformApi(`/billing/invoices/${invoiceId}/mark-paid-offline`, { method: 'PUT' })
    },

    async voidInvoice(invoiceId) {
      return await $platformApi(`/billing/invoices/${invoiceId}/void`, { method: 'PUT' })
    },

    async issueCreditNote(invoiceId) {
      return await $platformApi(`/billing/invoices/${invoiceId}/credit-note`, { method: 'POST' })
    },

    // ─── Subscription Actions (Phase 2.5) ───────────

    async cancelSubscription(id) {
      return await $platformApi(`/companies/${id}/subscription/cancel`, { method: 'PUT' })
    },

    async undoCancelSubscription(id) {
      return await $platformApi(`/companies/${id}/subscription/undo-cancel`, { method: 'PUT' })
    },

    async extendTrial(id, days) {
      return await $platformApi(`/companies/${id}/subscription/extend-trial`, {
        method: 'PUT',
        body: { days },
      })
    },

    // ─── Wallet History (Phase 2.6) ─────────────────

    async fetchWalletHistory(id) {
      return await $platformApi(`/companies/${id}/wallet-history`)
    },

    // ─── Payment Method Setup (Admin) ────────────────

    async createAdminSetupIntent(id) {
      return await $platformApi(`/companies/${id}/payment-methods/setup-intent`, { method: 'POST' })
    },

    async confirmAdminPaymentMethod(id, paymentMethodId) {
      return await $platformApi(`/companies/${id}/payment-methods/confirm`, {
        method: 'POST',
        body: { payment_method_id: paymentMethodId },
      })
    },

    // ─── Billing Widgets (Phase 3) ──────────────────

    async fetchCompanyWidgets(id, widgetKeys) {
      return await $platformApi('/dashboard/widgets/data', {
        method: 'POST',
        body: {
          widgets: widgetKeys,
          scope: 'company',
          company_id: id,
        },
      })
    },

    _updateCompanyInList(company) {
      const idx = this._companies.findIndex(c => c.id === company.id)
      if (idx !== -1)
        this._companies[idx] = company
    },
  },
})
