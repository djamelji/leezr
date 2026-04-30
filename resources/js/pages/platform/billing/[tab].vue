<script setup>
import BillingDashboard from './_BillingDashboard.vue'
import BillingSubscriptionsTab from './_BillingSubscriptionsTab.vue'
import BillingInvoicesTab from './_BillingInvoicesTab.vue'
import BillingPaymentsTab from './_BillingPaymentsTab.vue'
import BillingDunningTab from './_BillingDunningTab.vue'
import BillingScheduledDebitsTab from './_BillingScheduledDebitsTab.vue'
import BillingCreditNotesTab from './_BillingCreditNotesTab.vue'
import BillingCouponsTab from './_BillingCouponsTab.vue'
import BillingWalletsTab from './_BillingWalletsTab.vue'
import BillingForensicsTab from './_BillingForensicsTab.vue'
import BillingGovernanceTab from './_BillingGovernanceTab.vue'
import BillingLedgerTab from './_BillingLedgerTab.vue'
import BillingRecovery from './_BillingRecovery.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'view_billing',
    navActiveLink: 'platform-billing-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-billing-tab')
const router = useRouter()

const activeTab = computed({
  get: () => route.params.tab,
  set: val => router.replace({ name: 'platform-billing-tab', params: { tab: val } }),
})

const tabs = computed(() => [
  { title: t('platformBilling.tabs.dashboard'), icon: 'tabler-layout-dashboard', tab: 'overview' },
  { title: t('platformBilling.tabs.subscriptions'), icon: 'tabler-receipt', tab: 'subscriptions' },
  { title: t('platformBilling.tabs.invoices'), icon: 'tabler-file-invoice', tab: 'invoices' },
  { title: t('platformBilling.tabs.payments'), icon: 'tabler-cash', tab: 'payments' },
  { title: t('platformBilling.tabs.dunning'), icon: 'tabler-alert-triangle', tab: 'dunning' },
  { title: t('platformBilling.tabs.scheduledDebits'), icon: 'tabler-calendar-event', tab: 'scheduled-debits' },
  { title: t('platformBilling.tabs.creditNotes'), icon: 'tabler-receipt-refund', tab: 'credit-notes' },
  { title: t('platformBilling.tabs.coupons'), icon: 'tabler-ticket', tab: 'coupons' },
  { title: t('platformBilling.tabs.wallets'), icon: 'tabler-wallet', tab: 'wallets' },
  { title: t('platformBilling.tabs.forensics'), icon: 'tabler-history', tab: 'forensics' },
  { title: t('platformBilling.tabs.governance'), icon: 'tabler-shield-check', tab: 'governance' },
  { title: t('platformBilling.tabs.ledger'), icon: 'tabler-book', tab: 'ledger' },
  { title: t('platformBilling.tabs.recovery'), icon: 'tabler-first-aid-kit', tab: 'recovery' },
])
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('platformBilling.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('platformBilling.subtitle') }}
        </p>
      </div>
      <VBtn
        variant="tonal"
        color="secondary"
        prepend-icon="tabler-settings"
        :to="{ name: 'platform-settings-tab', params: { tab: 'billing' } }"
      >
        {{ t('platformBilling.tabs.settings') }}
      </VBtn>
    </div>

    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'platform-billing-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="overview">
        <BillingDashboard @switch-tab="tab => router.replace({ name: 'platform-billing-tab', params: { tab } })" />
      </VWindowItem>

      <VWindowItem value="subscriptions">
        <BillingSubscriptionsTab />
      </VWindowItem>

      <VWindowItem value="invoices">
        <BillingInvoicesTab />
      </VWindowItem>

      <VWindowItem value="payments">
        <BillingPaymentsTab />
      </VWindowItem>

      <VWindowItem value="dunning">
        <BillingDunningTab />
      </VWindowItem>

      <VWindowItem value="scheduled-debits">
        <BillingScheduledDebitsTab />
      </VWindowItem>

      <VWindowItem value="credit-notes">
        <BillingCreditNotesTab />
      </VWindowItem>

      <VWindowItem value="coupons">
        <BillingCouponsTab />
      </VWindowItem>

      <VWindowItem value="wallets">
        <BillingWalletsTab />
      </VWindowItem>

      <VWindowItem value="forensics">
        <BillingForensicsTab />
      </VWindowItem>

      <VWindowItem value="governance">
        <BillingGovernanceTab />
      </VWindowItem>

      <VWindowItem value="ledger">
        <BillingLedgerTab />
      </VWindowItem>

      <VWindowItem value="recovery">
        <BillingRecovery @switch-tab="tab => router.replace({ name: 'platform-billing-tab', params: { tab } })" />
      </VWindowItem>

    </VWindow>
  </div>
</template>
