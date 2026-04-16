<script setup>
definePage({ meta: { module: 'core.documents', surface: 'structure', permission: 'documents.view' } })

import DocumentsOverview from './_DocumentsOverview.vue'
import DocumentsRequests from './_DocumentsRequests.vue'
import DocumentsCompliance from './_DocumentsCompliance.vue'
import DocumentsVault from './_DocumentsVault.vue'
import DocumentsSettings from './_DocumentsSettings.vue'
import CreateDocumentTypeDrawer from '@/company/views/CreateDocumentTypeDrawer.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'

const { t } = useI18n()
const route = useRoute('company-documents-tab')
const router = useRouter()
const store = useCompanyDocumentsStore()
const { can } = useCompanyPermissionContext()

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

// ADR-418: Tab-level permission gating
const tabPermissions = {
  overview: null,
  requests: 'documents.manage',
  compliance: null,
  vault: null,
  settings: 'documents.configure',
}

const allTabs = computed(() => [
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

// Only show tabs user has permission for
const tabs = computed(() =>
  allTabs.value.filter(t => {
    const perm = tabPermissions[t.tab]

    return !perm || can(perm)
  }),
)

// ADR-418: Redirect if URL points to a restricted tab
watch(() => route.params.tab, tab => {
  const perm = tabPermissions[tab]

  if (perm && !can(perm)) {
    router.replace({ params: { tab: tabs.value[0]?.tab || 'overview' } })
  }
}, { immediate: true })

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

const { isLoading: pageLoading, isError: pageError, error: pageErrorMsg, retry: pageRetry } = useAsyncAction(async () => {
  // Page-level data (documents.view — guaranteed by router guard)
  await Promise.all([
    store.fetchCompanyDocuments(),
    store.fetchDocumentActivations(),
    store.fetchCompliance(),
    store.fetchActivity(),
  ])

  // ADR-418: Tab-specific data — only fetch if user has permission (non-blocking)
  if (can('documents.manage')) store.fetchRequests().catch(() => {})
  if (can('documents.configure')) store.fetchDocSettings().catch(() => {})
}, { immediate: true })
</script>

<template>
  <div>
    <!-- Loading -->
    <VSkeletonLoader
      v-if="pageLoading"
      type="tabs, card, card"
    />

    <!-- Error -->
    <ErrorState
      v-else-if="pageError"
      :message="pageErrorMsg"
      @retry="pageRetry"
    />

    <!-- Content -->
    <template v-else>
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
    </template>
  </div>
</template>
