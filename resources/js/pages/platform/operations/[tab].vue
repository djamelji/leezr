<script setup>
import OperationsHealth from './_OperationsHealth.vue'
import OperationsAlerts from './_OperationsAlerts.vue'
import OperationsSecurity from './_OperationsSecurity.vue'
import OperationsAi from './_OperationsAi.vue'
import OperationsUsageConsumption from './_OperationsUsageConsumption.vue'
import OperationsAutomations from './_OperationsAutomations.vue'
import OperationsRealtime from './_OperationsRealtime.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import AppTabFamily from '@/components/app/AppTabFamily.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.alerts',
    anyPermission: ['view_system_health', 'view_alerts', 'security.alerts.view', 'view_ai', 'view_usage_monitoring', 'manage_automations', 'realtime.metrics.view'], // ADR-801
    navActiveLink: 'platform-operations-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-operations-tab')
const router = useRouter()
const auth = usePlatformAuthStore()

// ADR-598 LOT C / ADR-601: operations tabs filtered by effective permission.
// `permissionAny` = visible if the user holds AT LEAST ONE of the listed permissions (used by the
// consolidated Usage & Consumption page, reachable with view_usage_monitoring OR view_ai).
const tabs = computed(() => [
  { title: t('platformOperations.tabs.health'), icon: 'tabler-heartbeat', tab: 'health', permission: 'view_system_health' },
  { title: t('platformOperations.tabs.alerts'), icon: 'tabler-bell-ringing', tab: 'alerts', permission: 'view_alerts' },
  { title: t('platformOperations.tabs.security'), icon: 'tabler-shield-lock', tab: 'security', permission: 'security.alerts.view' },
  { title: t('platformOperations.tabs.ai'), icon: 'tabler-brain', tab: 'ai', permission: 'view_ai' },
  { title: t('platformUsage.title'), icon: 'tabler-gauge', tab: 'usage', permissionAny: ['view_usage_monitoring', 'view_ai'] },
  { title: t('platformOperations.tabs.automations'), icon: 'tabler-robot', tab: 'automations', permission: 'manage_automations' },
  { title: t('platformOperations.tabs.realtime'), icon: 'tabler-broadcast', tab: 'realtime', permission: 'realtime.metrics.view' },
].filter(item => {
  if (item.permissionAny) return item.permissionAny.some(p => auth.hasPermission(p))

  return !item.permission || auth.hasPermission(item.permission)
}))

const activeTab = computed({
  get: () => {
    const allowed = tabs.value.map(tb => tb.tab)

    return allowed.includes(route.params.tab) ? route.params.tab : (allowed[0] ?? route.params.tab)
  },
  set: val => router.replace({ name: 'platform-operations-tab', params: { tab: val } }),
})

// ADR-861 (L7.7) — 7 tabs at one level → 4 groups : what runs now, what threatens, what is consumed, what runs alone
const GROUPS = [
  { key: 'supervision', icon: 'tabler-heartbeat', pages: ['health', 'realtime', 'alerts'] },
  { key: 'security', icon: 'tabler-shield-lock', pages: ['security'] },
  { key: 'consumption', icon: 'tabler-gauge', pages: ['usage', 'ai'] },
  { key: 'automations', icon: 'tabler-robot', pages: ['automations'] },
]
const groups = computed(() => GROUPS.map(g => ({ ...g, title: t(`l7.ops.group.${g.key}`), items: g.pages.map(k => tabs.value.find(x => x.tab === k)).filter(Boolean) })))
const hints = computed(() => ({ health: t('systemHealth.subtitle'), alerts: t('alerts.subtitle'), automations: t('automations.subtitle'), usage: t('platformUsage.subtitle') }))
</script>

<template>
  <div>
    <AppTabFamily
      :family="t('l7.ops.family')"
      :groups="groups"
      route-name="platform-operations-tab"
      :active="activeTab"
      :hints="hints"
      hint-prefix="l7.ops.hint"
      test="operations"
    />

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
        <OperationsUsageConsumption />
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
