<script setup>
import OperationsHealth from './_OperationsHealth.vue'
import OperationsAlerts from './_OperationsAlerts.vue'
import OperationsSecurity from './_OperationsSecurity.vue'
import OperationsAi from './_OperationsAi.vue'
import OperationsUsage from './_OperationsUsage.vue'
import OperationsAutomations from './_OperationsAutomations.vue'
import OperationsRealtime from './_OperationsRealtime.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.alerts',
    navActiveLink: 'platform-operations-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-operations-tab')
const router = useRouter()

const activeTab = computed({
  get: () => route.params.tab,
  set: val => router.replace({ name: 'platform-operations-tab', params: { tab: val } }),
})

const tabs = computed(() => [
  { title: t('platformOperations.tabs.health'), icon: 'tabler-heartbeat', tab: 'health' },
  { title: t('platformOperations.tabs.alerts'), icon: 'tabler-bell-ringing', tab: 'alerts' },
  { title: t('platformOperations.tabs.security'), icon: 'tabler-shield-lock', tab: 'security' },
  { title: t('platformOperations.tabs.ai'), icon: 'tabler-brain', tab: 'ai' },
  { title: t('platformOperations.tabs.usage'), icon: 'tabler-chart-bar', tab: 'usage' },
  { title: t('platformOperations.tabs.automations'), icon: 'tabler-robot', tab: 'automations' },
  { title: t('platformOperations.tabs.realtime'), icon: 'tabler-broadcast', tab: 'realtime' },
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
        :to="{ name: 'platform-operations-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="health">
        <OperationsHealth />
      </VWindowItem>

      <VWindowItem value="alerts">
        <OperationsAlerts />
      </VWindowItem>

      <VWindowItem value="security">
        <OperationsSecurity />
      </VWindowItem>

      <VWindowItem value="ai">
        <OperationsAi />
      </VWindowItem>

      <VWindowItem value="usage">
        <OperationsUsage />
      </VWindowItem>

      <VWindowItem value="automations">
        <OperationsAutomations />
      </VWindowItem>

      <VWindowItem value="realtime">
        <OperationsRealtime />
      </VWindowItem>
    </VWindow>
  </div>
</template>
