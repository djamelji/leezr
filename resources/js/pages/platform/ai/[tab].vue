<script setup>
import AiProvidersTab from './_AiProvidersTab.vue'
import AiUsageTab from './_AiUsageTab.vue'
import AiRoutingTab from './_AiRoutingTab.vue'
import AiSettingsTab from './_AiSettingsTab.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.ai',
    navActiveLink: 'platform-ai-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-ai-tab')

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = computed(() => [
  { title: t('platformAi.tabs.providers'), icon: 'tabler-server-cog', tab: 'providers' },
  { title: t('platformAi.tabs.usage'), icon: 'tabler-chart-bar', tab: 'usage' },
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
        :to="{ name: 'platform-ai-tab', params: { tab: item.tab } }"
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

      <VWindowItem value="routing">
        <AiRoutingTab />
      </VWindowItem>

      <VWindowItem value="settings">
        <AiSettingsTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
