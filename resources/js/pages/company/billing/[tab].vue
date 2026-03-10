<script setup>
import BillingInvoices from './_BillingInvoices.vue'
import BillingOverview from './_BillingOverview.vue'
import BillingPaymentMethods from './_BillingPaymentMethods.vue'
import BillingTimeline from './_BillingTimeline.vue'

definePage({
  meta: {
    surface: 'structure',
    module: 'core.billing',
    navActiveLink: 'company-billing-tab',
  },
})

const { t } = useI18n()
const route = useRoute('company-billing-tab')

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = computed(() => [
  { title: t('companyBilling.tabs.overview'), icon: 'tabler-layout-dashboard', tab: 'overview' },
  { title: t('companyBilling.tabs.invoices'), icon: 'tabler-file-invoice', tab: 'invoices' },
  { title: t('companyBilling.tabs.paymentMethods'), icon: 'tabler-credit-card', tab: 'payment-methods' },
  { title: t('companyBilling.tabs.activity'), icon: 'tabler-history', tab: 'activity' },
])
</script>

<template>
  <div>
    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'company-billing-tab', params: { tab: item.tab } }"
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
        <BillingOverview />
      </VWindowItem>

      <VWindowItem value="invoices">
        <BillingInvoices />
      </VWindowItem>

      <VWindowItem value="payment-methods">
        <BillingPaymentMethods />
      </VWindowItem>

      <VWindowItem value="activity">
        <BillingTimeline />
      </VWindowItem>
    </VWindow>
  </div>
</template>
