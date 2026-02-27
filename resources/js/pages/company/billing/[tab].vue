<script setup>
import BillingInvoices from './_BillingInvoices.vue'
import BillingPaymentMethods from './_BillingPaymentMethods.vue'
import BillingPayments from './_BillingPayments.vue'
import BillingWallet from './_BillingWallet.vue'

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
  { title: t('companyBilling.tabs.paymentMethods'), icon: 'tabler-credit-card', tab: 'payment-methods' },
  { title: t('companyBilling.tabs.invoices'), icon: 'tabler-file-invoice', tab: 'invoices' },
  { title: t('companyBilling.tabs.payments'), icon: 'tabler-cash', tab: 'payments' },
  { title: t('companyBilling.tabs.wallet'), icon: 'tabler-wallet', tab: 'wallet' },
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
      <VWindowItem value="payment-methods">
        <BillingPaymentMethods />
      </VWindowItem>

      <VWindowItem value="invoices">
        <BillingInvoices />
      </VWindowItem>

      <VWindowItem value="payments">
        <BillingPayments />
      </VWindowItem>

      <VWindowItem value="wallet">
        <BillingWallet />
      </VWindowItem>
    </VWindow>
  </div>
</template>
