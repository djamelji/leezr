<script setup>
import DashboardOverview from './_DashboardOverview.vue'
import DashboardActivity from './_DashboardActivity.vue'
import DashboardFunnel from './_DashboardFunnel.vue'
import AppAccessState from '@/components/app/AppAccessState.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.dashboard',
    permission: 'view_dashboard', // ADR-801 : = entrée de menu et API du cockpit
    navActiveLink: 'platform-dashboard-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-dashboard-tab')
const router = useRouter()

const activeTab = computed({
  get: () => route.params.tab,
  set: val => router.replace({ name: 'platform-dashboard-tab', params: { tab: val } }),
})

// ADR-839 (M3, UXP-03 / SEC-08) — a tab is shown only with the permission its API requires (no avoidable 403)
const platformAuth = usePlatformAuthStore()

const tabs = computed(() => [
  { title: t('platformDashboard.tabs.overview'), icon: 'tabler-layout-dashboard', tab: 'overview' },
  { title: t('platformDashboard.tabs.activity'), icon: 'tabler-activity', tab: 'activity', permission: 'view_audit_logs' },
  { title: t('platformDashboard.tabs.funnel'), icon: 'tabler-chart-funnel', tab: 'funnel', permission: 'view_usage_monitoring' },
].filter(item => !item.permission || platformAuth.hasPermission(item.permission)))

// ADR-862 — « Vue d'ensemble · Tableau de bord · Leezr » (the cockpit had no tab name)
watchEffect(() => { document.title = [tabs.value.find(x => x.tab === activeTab.value)?.title, t('l7.tabTitle.cockpit'), 'Leezr'].filter(Boolean).join(' · ') })
const tabAllowed = computed(() => tabs.value.some(item => item.tab === activeTab.value))
</script>

<template>
  <div>
    <!-- ADR-855 — compact section switch (same VTabs, same permissions) : the cockpit hero is the page's top -->
    <VTabs
      v-model="activeTab"
      class="cockpit-tabs"
      density="compact"
      :mandatory="false"
    >
      <VTab
        v-for="item in tabs"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'platform-dashboard-tab', params: { tab: item.tab } }"
      >
        <VIcon
          size="17"
          start
          :icon="item.icon"
        />
        {{ item.title }}
      </VTab>
    </VTabs>

    <AppAccessState
      v-if="!tabAllowed"
      compact
      :title="t('platformForbidden.tabTitle')"
      :message="t('platformForbidden.message')"
      :hint="t('platformForbidden.hint')"
      :show-back="false"
      :actions="[{ label: t('platformForbidden.firstTab'), to: { name: 'platform-dashboard-tab', params: { tab: 'overview' } }, testid: 'access-state-first-tab' }]"
      testid="platform-tab-forbidden"
    />

    <VWindow
      v-else
      v-model="activeTab"
      class="mt-4 disable-tab-transition"
      :touch="false"
    >
      <VWindowItem value="overview">
        <DashboardOverview />
      </VWindowItem>

      <VWindowItem value="activity">
        <DashboardActivity />
      </VWindowItem>

      <VWindowItem value="funnel">
        <DashboardFunnel />
      </VWindowItem>
    </VWindow>
  </div>
</template>

<style scoped>
.cockpit-tabs { border-block-end: 1px solid rgba(var(--v-border-color), .1); }
.cockpit-tabs :deep(.v-tab) { font-size: .875rem; min-inline-size: 0; padding-inline: .875rem; letter-spacing: 0; }
</style>
