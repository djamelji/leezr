<script setup>
import DashboardOverview from './_DashboardOverview.vue'
import DashboardActivity from './_DashboardActivity.vue'
import DashboardFunnel from './_DashboardFunnel.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.dashboard',
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

const tabs = computed(() => [
  { title: t('platformDashboard.tabs.overview'), icon: 'tabler-layout-dashboard', tab: 'overview' },
  { title: t('platformDashboard.tabs.activity'), icon: 'tabler-activity', tab: 'activity' },
  { title: t('platformDashboard.tabs.funnel'), icon: 'tabler-chart-funnel', tab: 'funnel' },
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
        :to="{ name: 'platform-dashboard-tab', params: { tab: item.tab } }"
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
