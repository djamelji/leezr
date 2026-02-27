<script setup>
import BillingInvoicesTab from './_BillingInvoicesTab.vue'
import BillingCreditNotesTab from './_BillingCreditNotesTab.vue'
import BillingPaymentsTab from './_BillingPaymentsTab.vue'
import BillingDunningTab from './_BillingDunningTab.vue'
import BillingWalletsTab from './_BillingWalletsTab.vue'
import BillingSubscriptionsTab from './_BillingSubscriptionsTab.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'view_billing',
  },
})

const { t } = useI18n()

const activeTab = ref('invoices')

const tabs = computed(() => [
  { title: t('platformBilling.tabs.invoices'), icon: 'tabler-file-invoice', value: 'invoices' },
  { title: t('platformBilling.tabs.creditNotes'), icon: 'tabler-receipt-refund', value: 'credit-notes' },
  { title: t('platformBilling.tabs.payments'), icon: 'tabler-cash', value: 'payments' },
  { title: t('platformBilling.tabs.dunning'), icon: 'tabler-alert-triangle', value: 'dunning' },
  { title: t('platformBilling.tabs.wallets'), icon: 'tabler-wallet', value: 'wallets' },
  { title: t('platformBilling.tabs.subscriptions'), icon: 'tabler-receipt', value: 'subscriptions' },
])
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
    </VWindow>
  </div>
</template>
