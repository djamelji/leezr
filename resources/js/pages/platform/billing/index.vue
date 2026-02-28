<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import BillingInvoicesTab from './_BillingInvoicesTab.vue'
import BillingCreditNotesTab from './_BillingCreditNotesTab.vue'
import BillingPaymentsTab from './_BillingPaymentsTab.vue'
import BillingDunningTab from './_BillingDunningTab.vue'
import BillingWalletsTab from './_BillingWalletsTab.vue'
import BillingSubscriptionsTab from './_BillingSubscriptionsTab.vue'
import BillingGovernanceTab from './_BillingGovernanceTab.vue'
import BillingLedgerTab from './_BillingLedgerTab.vue'
import BillingForensicsTab from './_BillingForensicsTab.vue'
import RevenueTrendWidget from './_RevenueTrendWidget.vue'
import RefundRatioWidget from './_RefundRatioWidget.vue'
import ArOutstandingWidget from './_ArOutstandingWidget.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'view_billing',
  },
})

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const activeTab = ref('invoices')

const tabs = computed(() => [
  { title: t('platformBilling.tabs.invoices'), icon: 'tabler-file-invoice', value: 'invoices' },
  { title: t('platformBilling.tabs.creditNotes'), icon: 'tabler-receipt-refund', value: 'credit-notes' },
  { title: t('platformBilling.tabs.payments'), icon: 'tabler-cash', value: 'payments' },
  { title: t('platformBilling.tabs.dunning'), icon: 'tabler-alert-triangle', value: 'dunning' },
  { title: t('platformBilling.tabs.wallets'), icon: 'tabler-wallet', value: 'wallets' },
  { title: t('platformBilling.tabs.subscriptions'), icon: 'tabler-receipt', value: 'subscriptions' },
  { title: t('platformBilling.tabs.governance'), icon: 'tabler-shield-check', value: 'governance' },
  { title: t('platformBilling.tabs.ledger'), icon: 'tabler-book', value: 'ledger' },
  { title: t('platformBilling.tabs.forensics'), icon: 'tabler-search', value: 'forensics' },
])

// ── Widgets ──
const widgetCompanyId = ref('')
const widgetPeriod = ref('30d')

const periodOptions = computed(() => [
  { title: t('platformBilling.widgets.period7d'), value: '7d' },
  { title: t('platformBilling.widgets.period30d'), value: '30d' },
  { title: t('platformBilling.widgets.period90d'), value: '90d' },
])

const loadWidgets = async () => {
  if (!widgetCompanyId.value) return
  store.setWidgetsPeriod(widgetPeriod.value)
  await store.fetchAllWidgets(Number(widgetCompanyId.value))
}

watch([widgetCompanyId, widgetPeriod], () => {
  if (widgetCompanyId.value) loadWidgets()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h4 class="text-h4">
        {{ t('platformBilling.title') }}
      </h4>
      <p class="text-body-1 mb-0">
        {{ t('platformBilling.subtitle') }}
      </p>
    </div>

    <!-- ═══ Financial Overview (D4e) ═══ -->
    <VCard class="mb-6">
      <VCardTitle>
        <VIcon
          icon="tabler-chart-bar"
          class="me-2"
        />
        {{ t('platformBilling.widgets.title') }}
      </VCardTitle>
      <VCardText>
        <VRow class="mb-4">
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model="widgetCompanyId"
              :label="t('platformBilling.governance.companyId')"
              type="number"
              :placeholder="t('platformBilling.governance.companyIdPlaceholder')"
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="widgetPeriod"
              :label="t('platformBilling.widgets.period')"
              :items="periodOptions"
              density="compact"
            />
          </VCol>
        </VRow>

        <div
          v-if="!widgetCompanyId"
          class="text-center pa-6 text-disabled"
        >
          {{ t('platformBilling.governance.selectCompanyFirst') }}
        </div>

        <VRow v-else>
          <VCol
            cols="12"
            md="8"
          >
            <RevenueTrendWidget
              :data="store.widgetData.revenue_trend"
              :loading="store.widgetLoading.revenue_trend || store.widgetsLoading"
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <RefundRatioWidget
              :data="store.widgetData.refund_ratio"
              :loading="store.widgetLoading.refund_ratio || store.widgetsLoading"
              class="mb-6"
            />
            <ArOutstandingWidget
              :data="store.widgetData.ar_outstanding"
              :loading="store.widgetLoading.ar_outstanding || store.widgetsLoading"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.value"
        :value="item.value"
      >
        <VIcon
          size="20"
          start
          :icon="item.icon"
        />
        {{ item.title }}
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeTab"
      class="mt-6 disable-tab-transition"
      :touch="false"
    >
      <VWindowItem value="invoices">
        <BillingInvoicesTab />
      </VWindowItem>

      <VWindowItem value="credit-notes">
        <BillingCreditNotesTab />
      </VWindowItem>

      <VWindowItem value="payments">
        <BillingPaymentsTab />
      </VWindowItem>

      <VWindowItem value="dunning">
        <BillingDunningTab />
      </VWindowItem>

      <VWindowItem value="wallets">
        <BillingWalletsTab />
      </VWindowItem>

      <VWindowItem value="subscriptions">
        <BillingSubscriptionsTab />
      </VWindowItem>

      <VWindowItem value="governance">
        <BillingGovernanceTab />
      </VWindowItem>

      <VWindowItem value="ledger">
        <BillingLedgerTab />
      </VWindowItem>

      <VWindowItem value="forensics">
        <BillingForensicsTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
