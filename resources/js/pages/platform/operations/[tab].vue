<script setup>
import OperationsHealth from './_OperationsHealth.vue'
import OperationsAlerts from './_OperationsAlerts.vue'
import OperationsSecurity from './_OperationsSecurity.vue'
import OperationsAi from './_OperationsAi.vue'
import OperationsUsage from './_OperationsUsage.vue'
import OperationsAutomations from './_OperationsAutomations.vue'
import OperationsRealtime from './_OperationsRealtime.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

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
const auth = usePlatformAuthStore()

// ADR-598 LOT C: operations tabs filtered by effective permission.
const tabs = computed(() => [
  { title: t('platformOperations.tabs.health'), icon: 'tabler-heartbeat', tab: 'health', permission: 'view_system_health' },
  { title: t('platformOperations.tabs.alerts'), icon: 'tabler-bell-ringing', tab: 'alerts', permission: 'view_alerts' },
  { title: t('platformOperations.tabs.security'), icon: 'tabler-shield-lock', tab: 'security', permission: 'security.alerts.view' },
  { title: t('platformOperations.tabs.ai'), icon: 'tabler-brain', tab: 'ai', permission: 'view_ai' },
  { title: t('platformOperations.tabs.usage'), icon: 'tabler-chart-bar', tab: 'usage', permission: 'view_usage_monitoring' },
  { title: t('platformOperations.tabs.automations'), icon: 'tabler-robot', tab: 'automations', permission: 'manage_automations' },
  { title: t('platformOperations.tabs.realtime'), icon: 'tabler-broadcast', tab: 'realtime', permission: 'realtime.metrics.view' },
].filter(item => !item.permission || auth.hasPermission(item.permission)))

const activeTab = computed({
  get: () => {
    const allowed = tabs.value.map(tb => tb.tab)

    return allowed.includes(route.params.tab) ? route.params.tab : (allowed[0] ?? route.params.tab)
  },
  set: val => router.replace({ name: 'platform-operations-tab', params: { tab: val } }),
})
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
