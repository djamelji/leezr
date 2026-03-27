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

onMounted(async () => {
  await Promise.all([
    settingsStore.fetchCompany(),
    settingsStore.fetchLegalStructure(),
  ])
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
      class="mt-6 disable-tab-transition"
      :touch="false"
    >
      <VWindowItem value="overview">
        <CompanyProfileOverview />
      </VWindowItem>
    </VWindow>
  </div>
</template>
