<script setup>
definePage({ meta: { module: 'core.settings', surface: 'structure', permission: 'settings.view' } })

import CompanyProfileOverview from './_CompanyProfileOverview.vue'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'

const { t } = useI18n()
const route = useRoute('company-profile-tab')
const settingsStore = useCompanySettingsStore()

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = computed(() => [
  {
    title: t('companyProfile.overview'),
    icon: 'tabler-building',
    tab: 'overview',
  },
])

const currentTabTitle = computed(() => {
  const current = tabs.value.find(tab => tab.tab === activeTab.value)

  return current?.title ?? ''
})

onMounted(async () => {
  await Promise.all([
    settingsStore.fetchCompany(),
    settingsStore.fetchLegalStructure(),
  ])
})
</script>

<template>
  <div>
    <SettingsReturnLink /> <!-- ADR-781 -->
    <PageBreadcrumbs
      :items="[
        { title: t('breadcrumbs.dashboard'), to: { name: 'dashboard' } },
        { title: t('breadcrumbs.profile'), to: { name: 'company-profile-tab', params: { tab: 'overview' } } },
        { title: currentTabTitle },
      ]"
    />

    <!-- ADR-782 (UX6) : en-tête commun ; onglets soulignés, masqués s'il n'y en a qu'un -->
    <AppPageHeader
      :title="t('nav.company.company-profile')"
      :description="t('companyForm.pageHint')"
    />
    <VTabs
      v-if="tabs.length > 1"
      v-model="activeTab"
      class="border-b"
    >
      <VTab
        v-for="item in tabs"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'company-profile-tab', params: { tab: item.tab } }"
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
      class="mt-5 disable-tab-transition"
      :touch="false"
    >
      <VWindowItem value="overview">
        <CompanyProfileOverview />
      </VWindowItem>
    </VWindow>
  </div>
</template>
