<script setup>
/**
 * Platform Companies — Tabbed master page (Companies + Members + Company Logs)
 * ADR-446: Replaces supervision/[tab].vue — single entry point for companies management.
 */
import CompaniesTab from './_CompaniesTab.vue'
import MembersTab from './_MembersTab.vue'
import CompanyLogsTab from './_CompanyLogsTab.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.companies',
    navActiveKey: 'platform-companies',
  },
})

const { t } = useI18n()

const currentTab = ref('companies')

const tabs = computed(() => [
  { title: t('companies.title'), icon: 'tabler-building', value: 'companies' },
  { title: t('platformCompanyUsers.title'), icon: 'tabler-users-group', value: 'members' },
  { title: t('audit.companyTab'), icon: 'tabler-file-search', value: 'logs' },
])
</script>

<template>
  <div>
    <VTabs
      v-model="currentTab"
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
      v-model="currentTab"
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
