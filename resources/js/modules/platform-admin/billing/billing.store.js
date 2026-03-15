import { defineStore, storeToRefs } from 'pinia'
import { usePlatformBillingSubscriptionsStore } from './billing-subscriptions.store'
import { usePlatformBillingInvoicesStore } from './billing-invoices.store'
import { usePlatformBillingFinancialStore } from './billing-financial.store'
import { usePlatformBillingMetricsStore } from './billing-metrics.store'

/**
 * Facade store — composes 4 sub-stores for backward compatibility.
 *
 * All 16 consumers continue to import usePlatformPaymentsStore unchanged.
 * New code can import sub-stores directly for better tree-shaking.
 *
 * Sub-stores:
 *   - billing-subscriptions: providers, config, policies, subscriptions, payment modules/rules
 *   - billing-invoices: invoice detail, mutations, read-only data (invoices, payments, etc.)
 *   - billing-financial: ledger, trial balance, periods, timeline, snapshots, drift
 *   - billing-metrics: widgets, metrics, recovery status
 */
export const usePlatformPaymentsStore = defineStore('platformPayments', () => {
  const sub = usePlatformBillingSubscriptionsStore()
  const inv = usePlatformBillingInvoicesStore()
  const fin = usePlatformBillingFinancialStore()
  const met = usePlatformBillingMetricsStore()

  return {
    // ── Reactive state + getters (from sub-stores via storeToRefs) ──
    ...storeToRefs(sub),
    ...storeToRefs(inv),
    ...storeToRefs(fin),
    ...storeToRefs(met),

    // ── Actions — subscriptions ──
    fetchProviders: sub.fetchProviders,
    fetchConfig: sub.fetchConfig,
    updateConfig: sub.updateConfig,
    fetchPolicies: sub.fetchPolicies,
    updatePolicies: sub.updatePolicies,
    fetchSubscriptions: sub.fetchSubscriptions,
    approveSubscription: sub.approveSubscription,
    rejectSubscription: sub.rejectSubscription,

    // ── Actions — payment modules & rules ──
    fetchPaymentModules: sub.fetchPaymentModules,
    installPaymentModule: sub.installPaymentModule,
    activatePaymentModule: sub.activatePaymentModule,
    deactivatePaymentModule: sub.deactivatePaymentModule,
    updatePaymentModuleCredentials: sub.updatePaymentModuleCredentials,
    checkPaymentModuleHealth: sub.checkPaymentModuleHealth,
    fetchPaymentRules: sub.fetchPaymentRules,
    createPaymentRule: sub.createPaymentRule,
    updatePaymentRule: sub.updatePaymentRule,
    deletePaymentRule: sub.deletePaymentRule,
    previewPaymentMethods: sub.previewPaymentMethods,

    // ── Actions — invoices ──
    fetchInvoiceDetail: inv.fetchInvoiceDetail,
    fetchAllInvoices: inv.fetchAllInvoices,
    fetchAllPayments: inv.fetchAllPayments,
    fetchAllCreditNotes: inv.fetchAllCreditNotes,
    fetchAllWallets: inv.fetchAllWallets,
    fetchAllSubscriptions: inv.fetchAllSubscriptions,
    fetchDunning: inv.fetchDunning,
    isMutationLoading: inv.isMutationLoading,
    markPaidOffline: inv.markPaidOffline,
    voidInvoice: inv.voidInvoice,
    updateInvoiceNotes: inv.updateInvoiceNotes,
    refundInvoice: inv.refundInvoice,
    retryInvoicePayment: inv.retryInvoicePayment,
    forceDunningTransition: inv.forceDunningTransition,
    issueManualCreditNote: inv.issueManualCreditNote,
    writeOffInvoice: inv.writeOffInvoice,
    bulkVoidInvoices: inv.bulkVoidInvoices,
    bulkRetryInvoices: inv.bulkRetryInvoices,

    // ── Actions — financial governance ──
    fetchTrialBalance: fin.fetchTrialBalance,
    fetchLedgerEntries: fin.fetchLedgerEntries,
    fetchFreezeState: fin.fetchFreezeState,
    fetchFinancialPeriods: fin.fetchFinancialPeriods,
    fetchTimeline: fin.fetchTimeline,
    fetchSnapshots: fin.fetchSnapshots,
    fetchDriftHistory: fin.fetchDriftHistory,
    closeFinancialPeriod: fin.closeFinancialPeriod,
    toggleFinancialFreeze: fin.toggleFinancialFreeze,
    runReconcile: fin.runReconcile,

    // ── Actions — widgets, metrics, recovery ──
    fetchWidgets: met.fetchWidgets,
    fetchWidget: met.fetchWidget,
    setWidgetsPeriod: met.setWidgetsPeriod,
    fetchAllWidgets: met.fetchAllWidgets,
    fetchMetrics: met.fetchMetrics,
    fetchRecoveryStatus: met.fetchRecoveryStatus,
    recoverCheckouts: met.recoverCheckouts,
    recoverWebhooks: met.recoverWebhooks,
    replayAllDeadLetters: met.replayAllDeadLetters,
    replayDeadLetter: met.replayDeadLetter,
    fetchDeadLetters: met.fetchDeadLetters,
  }
})

// Re-export sub-stores for direct access in new code
export { usePlatformBillingSubscriptionsStore } from './billing-subscriptions.store'
export { usePlatformBillingInvoicesStore } from './billing-invoices.store'
export { usePlatformBillingFinancialStore } from './billing-financial.store'
export { usePlatformBillingMetricsStore } from './billing-metrics.store'
