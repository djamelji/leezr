<script setup>
/**
 * AI Operations — formerly platform/ai/[tab].vue
 * Sub-tabs are now LOCAL ref (not URL-driven) since AI is nested inside Operations hub.
 */
import AiOperationsTab from '../ai/_AiOperationsTab.vue'
import AiProvidersTab from '../ai/_AiProvidersTab.vue'
import AiRoutingTab from '../ai/_AiRoutingTab.vue'
import AiSettingsTab from '../ai/_AiSettingsTab.vue'
import AiUsageTab from '../ai/_AiUsageTab.vue'

const { t } = useI18n()

const activeTab = ref('providers')

const tabs = computed(() => [
  { title: t('platformAi.tabs.providers'), icon: 'tabler-server-cog', tab: 'providers' },
  { title: t('platformAi.tabs.usage'), icon: 'tabler-chart-bar', tab: 'usage' },
  { title: t('platformAi.tabs.operations'), icon: 'tabler-activity', tab: 'operations' },
  { title: t('platformAi.tabs.routing'), icon: 'tabler-route', tab: 'routing' },
  { title: t('platformAi.tabs.settings'), icon: 'tabler-settings', tab: 'settings' },
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
      <VWindowItem value="providers">
        <AiProvidersTab />
      </VWindowItem>

      <VWindowItem value="usage">
        <AiUsageTab />
      </VWindowItem>

      <VWindowItem value="operations">
        <AiOperationsTab />
      </VWindowItem>

      <VWindowItem value="routing">
        <AiRoutingTab />
      </VWindowItem>

      <VWindowItem value="settings">
        <AiSettingsTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
