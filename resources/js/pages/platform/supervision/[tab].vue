<script setup>
/**
 * Platform Supervision — Tabbed master page (Companies + Members + Company Logs)
 * Pattern: access/[tab].vue
 * ADR-381
 */
import CompaniesTab from './_CompaniesTab.vue'
import MembersTab from './_MembersTab.vue'
import CompanyLogsTab from './_CompanyLogsTab.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.companies',
    navActiveLink: 'platform-supervision-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-supervision-tab')

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = computed(() => [
  { title: t('companies.title'), icon: 'tabler-building', tab: 'companies' },
  { title: t('platformCompanyUsers.title'), icon: 'tabler-users-group', tab: 'members' },
  { title: t('audit.companyTab'), icon: 'tabler-file-search', tab: 'logs' },
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
        :to="{ name: 'platform-supervision-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="companies">
        <CompaniesTab />
      </VWindowItem>

      <VWindowItem value="members">
        <MembersTab />
      </VWindowItem>

      <VWindowItem value="logs">
        <CompanyLogsTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
