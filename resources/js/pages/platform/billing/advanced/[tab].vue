<script setup>
import BillingCreditNotesTab from '../_BillingCreditNotesTab.vue'
import BillingPaymentsTab from '../_BillingPaymentsTab.vue'
import BillingWalletsTab from '../_BillingWalletsTab.vue'
import BillingGovernanceTab from '../_BillingGovernanceTab.vue'
import BillingLedgerTab from '../_BillingLedgerTab.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'view_billing',
    navActiveLink: 'platform-billing',
  },
})

const { t } = useI18n()
const route = useRoute('platform-billing-advanced-tab')

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = computed(() => [
  { title: t('platformBilling.tabs.creditNotes'), icon: 'tabler-receipt-refund', tab: 'credit-notes' },
  { title: t('platformBilling.tabs.payments'), icon: 'tabler-cash', tab: 'payments' },
  { title: t('platformBilling.tabs.wallets'), icon: 'tabler-wallet', tab: 'wallets' },
  { title: t('platformBilling.tabs.governance'), icon: 'tabler-shield-check', tab: 'governance' },
  { title: t('platformBilling.tabs.ledger'), icon: 'tabler-book', tab: 'ledger' },
])
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('platformBilling.advanced.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('platformBilling.advanced.subtitle') }}
        </p>
      </div>
      <VBtn
        variant="tonal"
        color="secondary"
        prepend-icon="tabler-arrow-left"
        :to="{ name: 'platform-billing' }"
      >
        {{ t('platformBilling.advanced.backToBilling') }}
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
        :to="{ name: 'platform-billing-advanced-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="credit-notes">
        <BillingCreditNotesTab />
      </VWindowItem>

      <VWindowItem value="payments">
        <BillingPaymentsTab />
      </VWindowItem>

      <VWindowItem value="wallets">
        <BillingWalletsTab />
      </VWindowItem>

      <VWindowItem value="governance">
        <BillingGovernanceTab />
      </VWindowItem>

      <VWindowItem value="ledger">
        <BillingLedgerTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
