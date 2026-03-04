<script setup>
definePage({ meta: { module: 'core.settings', surface: 'structure' } })

import CompanyProfileOverview from './_CompanyProfileOverview.vue'
import CompanyProfileDocuments from './_CompanyProfileDocuments.vue'
import DocumentTypesDrawer from '@/company/views/DocumentTypesDrawer.vue'
import CreateDocumentTypeDrawer from '@/company/views/CreateDocumentTypeDrawer.vue'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAuthStore } from '@/core/stores/auth'

const { t } = useI18n()
const route = useRoute('company-profile-tab')
const router = useRouter()
const settingsStore = useCompanySettingsStore()
const auth = useAuthStore()

const canEdit = computed(() => auth.hasPermission('settings.manage'))

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
  {
    title: t('companyProfile.documents'),
    icon: 'tabler-folder',
    tab: 'documents',
  },
])

// ─── Drawers (outside VWindow to avoid overlay clipping) ─
const isTypesDrawerOpen = ref(false)
const isCreateDrawerOpen = ref(false)

onMounted(async () => {
  await Promise.all([
    settingsStore.fetchCompany(),
    settingsStore.fetchLegalStructure(),
    settingsStore.fetchDocumentActivations(),
    settingsStore.fetchCompanyDocuments(),
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

      <VWindowItem value="documents">
        <CompanyProfileDocuments
          @open-types-drawer="isTypesDrawerOpen = true"
          @open-create-drawer="isCreateDrawerOpen = true"
        />
      </VWindowItem>
    </VWindow>

    <!-- Drawers live at page level (outside VWindow) for correct overlay -->
    <DocumentTypesDrawer
      v-model:is-drawer-open="isTypesDrawerOpen"
      :can-edit="canEdit"
      @create-custom="isCreateDrawerOpen = true"
    />

    <CreateDocumentTypeDrawer
      v-model:is-drawer-open="isCreateDrawerOpen"
    />
  </div>
</template>
