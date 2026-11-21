<script setup>
/**
 * Platform Companies — Tabbed master page (Companies + Members + Company Logs)
 * ADR-446: Replaces supervision/[tab].vue — single entry point for companies management.
 * ADR-834 : each tab only for the permission its API requires ; a creation operator (`create_companies`
 * without `view_companies`) sees the companies he created and their start of service, nothing else.
 */
import CompaniesTab from './_CompaniesTab.vue'
import MembersTab from './_MembersTab.vue'
import CompanyLogsTab from './_CompanyLogsTab.vue'
import MyCreatedCompanies from './_MyCreatedCompanies.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.companies',
    // ADR-801 : = GET /platform/companies (view_companies) ; ADR-834 : or GET /platform/company-creation/companies
    anyPermission: ['view_companies', 'create_companies'],
    navActiveKey: 'platform-companies',
  },
})

const { t } = useI18n()
const platformAuth = usePlatformAuthStore()
const canViewAll = computed(() => platformAuth.hasPermission('view_companies'))

const currentTab = ref('companies')

const tabs = computed(() => [
  { title: t('companies.title'), icon: 'tabler-building', value: 'companies', permission: 'view_companies' },
  { title: t('platformCompanyUsers.title'), icon: 'tabler-users-group', value: 'members', permission: 'view_company_users' },
  { title: t('audit.companyTab'), icon: 'tabler-file-search', value: 'logs', permission: 'view_audit_logs' },
].filter(tab => platformAuth.hasPermission(tab.permission)))

watchEffect(() => {
  if (tabs.value.length && !tabs.value.some(tab => tab.value === currentTab.value))
    currentTab.value = tabs.value[0].value
})
</script>

<template>
  <MyCreatedCompanies v-if="!canViewAll" />

  <div v-else>
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

      <VWindowItem
        v-if="tabs.some(tab => tab.value === 'members')"
        value="members"
      >
        <MembersTab />
      </VWindowItem>

      <VWindowItem
        v-if="tabs.some(tab => tab.value === 'logs')"
        value="logs"
      >
        <CompanyLogsTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
