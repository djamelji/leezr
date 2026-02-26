<script setup>
import PaymentModulesTab from './_PaymentModulesTab.vue'
import PaymentPoliciesTab from './_PaymentPoliciesTab.vue'
import PaymentRulesTab from './_PaymentRulesTab.vue'
import PaymentSubscriptionsTab from './_PaymentSubscriptionsTab.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'manage_billing',
  },
})

const { t } = useI18n()

const activeTab = ref('modules')

const tabs = computed(() => [
  { title: t('payments.tabs.modules'), icon: 'tabler-plug', value: 'modules' },
  { title: t('payments.tabs.rules'), icon: 'tabler-list-check', value: 'rules' },
  { title: t('payments.tabs.policies'), icon: 'tabler-shield-check', value: 'policies' },
  { title: t('payments.tabs.subscriptions'), icon: 'tabler-receipt', value: 'subscriptions' },
])
</script>

<template>
  <div>
    <div class="mb-6">
      <h4 class="text-h4">
        {{ t('payments.title') }}
      </h4>
      <p class="text-body-1 mb-0">
        {{ t('payments.subtitle') }}
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
      <VWindowItem value="modules">
        <PaymentModulesTab />
      </VWindowItem>

      <VWindowItem value="rules">
        <PaymentRulesTab />
      </VWindowItem>

      <VWindowItem value="policies">
        <PaymentPoliciesTab />
      </VWindowItem>

      <VWindowItem value="subscriptions">
        <PaymentSubscriptionsTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
