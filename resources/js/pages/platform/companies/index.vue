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
import AppPageHeader from '@/components/app/AppPageHeader.vue'

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

// ADR-861 (L7.7) — the page says where the user is and what each view answers ; the main action (create) sits in the header
const canCreateCompany = computed(() => platformAuth.hasPermission('create_companies')) // ADR-834 : = POST /platform/companies
const activeTitle = computed(() => tabs.value.find(x => x.value === currentTab.value)?.title || t('companies.title'))
watchEffect(() => { document.title = [activeTitle.value, t('l7.companies.family'), 'Leezr'].filter((v, i, a) => a.indexOf(v) === i).join(' · ') }) // ADR-862 : no « Entreprises · Entreprises »

watchEffect(() => {
  if (tabs.value.length && !tabs.value.some(tab => tab.value === currentTab.value))
    currentTab.value = tabs.value[0].value
})
</script>

<template>
  <MyCreatedCompanies v-if="!canViewAll" />

  <div v-else>
    <AppPageHeader
      :eyebrow="t('l7.companies.group')"
      :title="activeTitle"
      :description="t(`l7.companies.hint.${currentTab}`)"
      data-test="companies-header"
    >
      <template
        v-if="canCreateCompany && currentTab === 'companies'"
        #actions
      >
        <VBtn
          prepend-icon="tabler-plus"
          :to="{ name: 'platform-companies-create' }"
          data-testid="new-company"
        >
          {{ t('platformCompanyCreation.newCompany') }}
        </VBtn>
      </template>
    </AppPageHeader>
    <VTabs
      v-model="currentTab"
      class="v-tabs-pill"
      show-arrows
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
