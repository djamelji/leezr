<script setup>
definePage({ meta: { module: 'core.documents', surface: 'structure' } })

import DocumentsOverview from './_DocumentsOverview.vue'
import DocumentsRequests from './_DocumentsRequests.vue'
import DocumentsCompliance from './_DocumentsCompliance.vue'
import DocumentsVault from './_DocumentsVault.vue'
import DocumentsSettings from './_DocumentsSettings.vue'
import CreateDocumentTypeDrawer from '@/company/views/CreateDocumentTypeDrawer.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'

const { t } = useI18n()
const route = useRoute('company-documents-tab')
const router = useRouter()
const store = useCompanyDocumentsStore()

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = computed(() => [
  {
    title: t('companyDocuments.tabs.overview'),
    icon: 'tabler-layout-dashboard',
    tab: 'overview',
  },
  {
    title: t('companyDocuments.tabs.requests'),
    icon: 'tabler-file-text',
    tab: 'requests',
    badge: store.submittedRequestsCount || null,
  },
  {
    title: t('companyDocuments.tabs.compliance'),
    icon: 'tabler-shield-check',
    tab: 'compliance',
  },
  {
    title: t('companyDocuments.tabs.vault'),
    icon: 'tabler-folder',
    tab: 'vault',
  },
  {
    title: t('companyDocuments.tabs.settings'),
    icon: 'tabler-settings',
    tab: 'settings',
  },
])

// ─── Create/Edit drawer ─────────────────────────────────
const isDrawerOpen = ref(false)
const editDocument = ref(null)

const openCreateDrawer = () => {
  editDocument.value = null
  isDrawerOpen.value = true
}

const openEditDrawer = doc => {
  editDocument.value = doc
  isDrawerOpen.value = true
}

const handleDrawerDone = async () => {
  isDrawerOpen.value = false
  editDocument.value = null
  await store.fetchDocumentActivations()
}

const navigateToTab = tab => {
  router.push({ name: 'company-documents-tab', params: { tab } })
}

onMounted(async () => {
  await Promise.all([
    store.fetchCompanyDocuments(),
    store.fetchDocumentActivations(),
    store.fetchCompliance(),
    store.fetchRequests(),
    store.fetchActivity(),
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
        :to="{ name: 'company-documents-tab', params: { tab: item.tab } }"
      >
        <VIcon
          size="20"
          start
          :icon="item.icon"
        />
        {{ item.title }}
        <VBadge
          v-if="item.badge"
          :content="item.badge"
          color="error"
          floating
          class="ms-2"
        />
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeTab"
      class="mt-6 disable-tab-transition"
      :touch="false"
    >
      <VWindowItem value="overview">
        <DocumentsOverview @navigate="navigateToTab" />
      </VWindowItem>

      <VWindowItem value="requests">
        <DocumentsRequests />
      </VWindowItem>

      <VWindowItem value="compliance">
        <DocumentsCompliance />
      </VWindowItem>

      <VWindowItem value="vault">
        <DocumentsVault @open-create-drawer="openCreateDrawer" />
      </VWindowItem>

      <VWindowItem value="settings">
        <DocumentsSettings
          @open-create-drawer="openCreateDrawer"
          @edit-custom-type="openEditDrawer"
        />
      </VWindowItem>
    </VWindow>

    <!-- Drawer at page level (outside VWindow) -->
    <CreateDocumentTypeDrawer
      v-model:is-drawer-open="isDrawerOpen"
      :edit-document="editDocument"
      @created="handleDrawerDone"
      @updated="handleDrawerDone"
    />
  </div>
</template>
