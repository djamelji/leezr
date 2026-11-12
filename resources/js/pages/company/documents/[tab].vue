<script setup>
// ADR-766 (M7) — ESPACE DOCUMENTS UNIFIÉ : une seule entrée Documents. Accès par `anyPermission`
// (gouvernance DMS `documents.view` OU registre RH `workforce_documents.view` OU signatures
// `workforce_signature.view`). Chaque onglet reste gated par sa permission RÉELLE ; aucun élargissement.
definePage({ meta: { module: 'core.documents', surface: 'structure', anyPermission: ['documents.view', 'workforce_documents.view', 'workforce_signature.view'] } })

import DocumentsOverview from './_DocumentsOverview.vue'
import DocumentsRequests from './_DocumentsRequests.vue'
import DocumentsCampaigns from './_DocumentsCampaigns.vue'
import DocumentsPayslipImport from './_DocumentsPayslipImport.vue'
import DocumentsCompliance from './_DocumentsCompliance.vue'
import DocumentsVault from './_DocumentsVault.vue'
import DocumentsSettings from './_DocumentsSettings.vue'
// Registre RH (docs générés + modèles + signatures) RÉUTILISÉ en onglet via `embedded` (ADR-766).
import HrDocuments from '@/pages/company/workforce/documents/index.vue'
import CreateDocumentTypeDrawer from '@/company/views/CreateDocumentTypeDrawer.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'

const { t } = useI18n()
const route = useRoute('company-documents-tab')
const router = useRouter()
const store = useCompanyDocumentsStore()
const { can, moduleActive } = useCompanyPermissionContext()

// L'onglet RH n'apparaît que si le module workforce_documents est actif ET l'utilisateur a le droit.
const canHr = computed(() => moduleActive('workforce_documents') && can('workforce_documents.view'))

// ADR-767 : un importateur (`payslips.import` seul) accède à l'onglet pour téléverser et suivre SES lots ;
// la revue (items/affectations/publication) reste gated `payslips.review`/`.publish` dans l'onglet et côté serveur.
const canPayslips = computed(() => can('payslips.import') || can('payslips.review'))

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

// ADR-418/766: Tab-level permission gating. Les onglets de gouvernance DMS exigent `documents.view`
// (donc INVISIBLES pour un profil qui n'a que `workforce_documents.view` → pas de fetch en 403) ;
// l'onglet RH exige `workforce_documents.view` + module actif (via `hrVisible`).
const tabPermissions = {
  overview: 'documents.view',
  requests: 'documents.manage',
  campaigns: 'documents.manage',
  payslips: null, // ADR-767 : visibilité via `canPayslips` (import OU revue), contenu gated dans l'onglet
  compliance: 'documents.view',
  vault: 'documents.view',
  settings: 'documents.configure',
  hr: null, // visibilité pilotée par `canHr` (module + permission), pas par une clé simple
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
    title: t('documents.campaign.tab'),
    icon: 'tabler-send',
    tab: 'campaigns',
  },
  {
    title: t('documents.payslip.tab'),
    icon: 'tabler-cash',
    tab: 'payslips',
    visible: canPayslips.value,
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
    title: t('companyDocuments.tabs.hr'),
    icon: 'tabler-file-certificate',
    tab: 'hr',
    visible: canHr.value,
  },
  {
    title: t('companyDocuments.tabs.settings'),
    icon: 'tabler-settings',
    tab: 'settings',
  },
])

// Only show tabs user has permission for (+ visibilité explicite pour l'onglet RH).
const tabs = computed(() =>
  allTabs.value.filter(t => {
    if (t.visible === false) return false
    const perm = tabPermissions[t.tab]

    return !perm || can(perm)
  }),
)

// ADR-418/766: Redirect if URL points to a tab the user cannot see (permission OU visibilité RH).
watch(() => route.params.tab, tab => {
  const visible = tabs.value.some(t => t.tab === tab)

  if (!visible) {
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
  // ADR-766 : les données de gouvernance DMS exigent `documents.view`. Un profil qui n'a que
  // `workforce_documents.view` (onglet RH) NE déclenche AUCUN appel `documents.*` (évite les 403).
  if (can('documents.view')) {
    await Promise.all([
      store.fetchCompanyDocuments(),
      store.fetchDocumentActivations(),
      store.fetchCompliance(),
      store.fetchActivity(),
    ])

    // ADR-418: Tab-specific data — only fetch if user has permission (non-blocking)
    if (can('documents.manage')) store.fetchRequests().catch(() => {})
    if (can('documents.configure')) store.fetchDocSettings().catch(() => {})
  }
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

      <VWindowItem value="campaigns">
        <DocumentsCampaigns />
      </VWindowItem>

      <VWindowItem value="payslips">
        <DocumentsPayslipImport />
      </VWindowItem>

      <VWindowItem value="compliance">
        <DocumentsCompliance />
      </VWindowItem>

      <VWindowItem value="vault">
        <DocumentsVault @open-create-drawer="openCreateDrawer" />
      </VWindowItem>

      <!-- Registre RH (docs générés + modèles + signatures) RÉUTILISÉ — gating module + permission -->
      <VWindowItem
        v-if="canHr"
        value="hr"
      >
        <HrDocuments
          v-if="activeTab === 'hr'"
          embedded
        />
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
