<script setup>
import BillingDashboard from './_BillingDashboard.vue'
import BillingSubscriptionsTab from './_BillingSubscriptionsTab.vue'
import BillingInvoicesTab from './_BillingInvoicesTab.vue'
import BillingDunningTab from './_BillingDunningTab.vue'
import BillingRecovery from './_BillingRecovery.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'view_billing',
  },
})

const { t } = useI18n()

const activeTab = ref('dashboard')

const tabs = computed(() => [
  { title: t('platformBilling.tabs.dashboard'), icon: 'tabler-layout-dashboard', value: 'dashboard' },
  { title: t('platformBilling.tabs.subscriptions'), icon: 'tabler-receipt', value: 'subscriptions' },
  { title: t('platformBilling.tabs.invoices'), icon: 'tabler-file-invoice', value: 'invoices' },
  { title: t('platformBilling.tabs.dunning'), icon: 'tabler-alert-triangle', value: 'dunning' },
  { title: t('platformBilling.tabs.recovery'), icon: 'tabler-first-aid-kit', value: 'recovery' },
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
      <div class="d-flex gap-2">
        <VBtn
          variant="tonal"
          color="warning"
          prepend-icon="tabler-device-analytics"
          :to="{ name: 'platform-billing-advanced-tab', params: { tab: 'credit-notes' } }"
        >
          {{ t('platformBilling.advanced.button') }}
        </VBtn>
        <VBtn
          variant="tonal"
          color="secondary"
          prepend-icon="tabler-settings"
          :to="{ name: 'platform-billing-settings-tab', params: { tab: 'general' } }"
        >
          {{ t('platformBilling.tabs.settings') }}
        </VBtn>
      </div>
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
      <VWindowItem value="dashboard">
        <BillingDashboard @switch-tab="tab => activeTab = tab" />
      </VWindowItem>

      <VWindowItem value="subscriptions">
        <BillingSubscriptionsTab />
      </VWindowItem>

      <VWindowItem value="invoices">
        <BillingInvoicesTab />
      </VWindowItem>

      <VWindowItem value="dunning">
        <BillingDunningTab />
      </VWindowItem>

      <VWindowItem value="recovery">
        <BillingRecovery />
      </VWindowItem>
    </VWindow>
  </div>
</template>
