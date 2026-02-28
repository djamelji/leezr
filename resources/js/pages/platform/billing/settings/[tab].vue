<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import SettingsGeneral from './_SettingsGeneral.vue'
import SettingsProviders from './_SettingsProviders.vue'
import SettingsPolicies from './_SettingsPolicies.vue'
import SettingsAudit from './_SettingsAudit.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'manage_billing',
    navActiveLink: 'billing-settings',
  },
})

const { t } = useI18n()
const route = useRoute('platform-billing-settings-tab')
const auth = usePlatformAuthStore()

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = computed(() => {
  const all = [
    { title: t('billingSettings.tabs.general'), icon: 'tabler-settings', tab: 'general', permission: 'manage_billing' },
    { title: t('billingSettings.tabs.providers'), icon: 'tabler-plug', tab: 'providers', permission: 'manage_billing_providers' },
    { title: t('billingSettings.tabs.policies'), icon: 'tabler-shield-check', tab: 'policies', permission: 'manage_billing_policies' },
    { title: t('billingSettings.tabs.audit'), icon: 'tabler-search', tab: 'audit', permission: 'view_billing_audit' },
  ]

  return all.filter(item => auth.hasPermission(item.permission))
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h4 class="text-h4">
        {{ t('billingSettings.title') }}
      </h4>
      <p class="text-body-1 mb-0">
        {{ t('billingSettings.subtitle') }}
      </p>
    </div>

    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'platform-billing-settings-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="general">
        <SettingsGeneral />
      </VWindowItem>

      <VWindowItem value="providers">
        <SettingsProviders />
      </VWindowItem>

      <VWindowItem value="policies">
        <SettingsPolicies />
      </VWindowItem>

      <VWindowItem value="audit">
        <SettingsAudit />
      </VWindowItem>
    </VWindow>
  </div>
</template>
