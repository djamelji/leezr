<script setup>
// ADR-766 (M7) — ESPACE DOCUMENTS UNIFIÉ : une seule entrée Documents. Accès par `anyPermission`
// (gouvernance DMS `documents.view` OU registre RH `workforce_documents.view` OU signatures
// `workforce_signature.view`). Chaque onglet reste gated par sa permission RÉELLE ; aucun élargissement.
definePage({ meta: { module: 'core.documents', surface: 'structure', anyPermission: ['documents.view', 'workforce_documents.view', 'workforce_signature.view'] } })

import DocumentsTodo from './_DocumentsTodo.vue'
import DocumentsSearch from './_DocumentsSearch.vue'
import DocumentsRequests from './_DocumentsRequests.vue'
import DocumentsCampaigns from './_DocumentsCampaigns.vue'
import DocumentsPayslipImport from './_DocumentsPayslipImport.vue'
import DocumentsCompliance from './_DocumentsCompliance.vue'
import DocumentsVault from './_DocumentsVault.vue'
import DocumentsSettings from './_DocumentsSettings.vue'
// Registre RH (documents générés · modèles · signatures) RÉUTILISÉ vue par vue (`only`).
import HrDocuments from '@/pages/company/workforce/documents/_HrDocumentsView.vue'
import SignaturePage from '@/pages/company/workforce/signature/_SignaturesView.vue'
import SignatureWizard from '@/pages/company/workforce/signature/_SignatureWizard.vue'
import CreateDocumentTypeDrawer from '@/company/views/CreateDocumentTypeDrawer.vue'
import DocumentDepositDialog from '@/company/views/DocumentDepositDialog.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useModuleStore } from '@/core/stores/module'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'

const { t } = useI18n()
const route = useRoute('company-documents-tab')
const router = useRouter()
const store = useCompanyDocumentsStore()
const { can, moduleActive } = useCompanyPermissionContext()
const moduleStore = useModuleStore() // ADR-805

// ── ADR-778 (Company Experience 2.0 / UX3) — Documents par INTENTION ────────────────────────────────
// Quatre vues : À traiter · Entreprise · Collaborateurs · Signatures. Les fonctions avancées
// (campagnes, import de bulletins, conformité détaillée, modèles, types & réglages) restent des vues à
// part entière, ouvertes par « Outils ». Chaque vue garde SA permission réelle : ranger deux
// fonctions dans une vue ne fusionne ni leurs droits ni leurs données.
const canHr = computed(() => moduleActive('workforce_documents') && can('workforce_documents.view'))
const peopleView = ref('requests') // ADR-822 : registre affiché dans l'onglet Collaborateurs
const canSignatures = computed(() => moduleActive('workforce_signature') && can('workforce_signature.view'))
const canTemplates = computed(() => moduleActive('workforce_contract') && can('workforce_contract.view'))
// ADR-767 : un importateur (`payslips.import` seul) suit SES lots ; la revue reste gated dans la vue et côté serveur.
const canPayslips = computed(() => can('payslips.import') || can('payslips.review'))

const mainViews = computed(() => [
  { tab: 'todo', title: t('documents.views.todo'), icon: 'tabler-checklist', show: can('documents.view') || canSignatures.value, badge: can('documents.manage') ? store.submittedRequestsCount : 0 },
  { tab: 'company', title: t('documents.views.company'), icon: 'tabler-building', show: can('documents.view') },
  { tab: 'people', title: t('documents.views.people'), icon: 'tabler-users', show: can('documents.manage') || canHr.value },
  { tab: 'signatures', title: t('documents.views.signatures'), icon: 'tabler-signature', show: canSignatures.value },
].filter(v => v.show))

const toolViews = computed(() => [
  { tab: 'campaigns', title: t('documents.campaign.tab'), hint: t('documents.tools.campaignsHint'), icon: 'tabler-send', show: can('documents.manage') },
  { tab: 'payslips', title: t('documents.payslip.tab'), hint: t('documents.tools.payslipsHint'), icon: 'tabler-cash', show: canPayslips.value },
  { tab: 'compliance', title: t('companyDocuments.tabs.compliance'), hint: t('documents.tools.complianceHint'), icon: 'tabler-shield-check', show: can('documents.view') },
  { tab: 'templates', title: t('documents.tools.templates'), hint: t('documents.tools.templatesHint'), icon: 'tabler-file-text', show: canTemplates.value },
  { tab: 'settings', title: t('documents.tools.settings'), hint: t('documents.tools.settingsHint'), icon: 'tabler-settings', show: can('documents.configure') },
].filter(v => v.show))

// ADR-805 — retrouver la configuration des modules documentaires depuis l'espace Documents (même assistant,
// aucune nouvelle page) : seulement les modules actifs avec un assistant, pour qui gère les modules.
const configModules = computed(() => !can('modules.manage') ? [] : ['workforce_documents', 'workforce_contract', 'workforce_signature']
  .map(k => moduleStore.modules.find(m => m.key === k))
  .filter(m => m && m.is_active && m.setup_status))

// Anciens onglets : leurs liens restent valides et ouvrent la vue qui les contient désormais.
const resolveLegacy = (tab, query) => ({
  overview: 'todo',
  vault: 'company',
  requests: 'people',
  hr: query.tab === 'signatures' ? 'signatures' : query.tab === 'templates' ? 'templates' : 'people',
}[tab] || tab)

const activeTab = computed(() => route.params.tab)
const currentTool = computed(() => toolViews.value.find(v => v.tab === activeTab.value) || null)

watch(() => route.params.tab, tab => {
  const target = resolveLegacy(tab, route.query)
  const allowed = [...mainViews.value, ...toolViews.value].some(v => v.tab === target)
  const next = allowed ? target : (mainViews.value[0]?.tab || toolViews.value[0]?.tab || 'todo')

  if (next !== tab) {
    const { tab: _, ...query } = route.query

    router.replace({ params: { tab: next }, query: tab === 'hr' ? query : route.query })
  }
}, { immediate: true })

// ── Recherche transversale (serveur) ──
const term = ref(typeof route.query.q === 'string' ? route.query.q : '')
const searching = ref(term.value !== '')
watch(term, v => { if (v.trim() !== '') searching.value = true })
const closeSearch = () => { searching.value = false; term.value = '' }

// ── « Nouveau » : chaque entrée ouvre directement le bon formulaire ──
const depositOpen = ref(false)
const signOpen = ref(false)
const go = (tab, query = {}) => router.push({ name: 'company-documents-tab', params: { tab }, query })
const newActions = computed(() => [
  can('documents.manage') ? { key: 'deposit', icon: 'tabler-file-upload', run: () => { depositOpen.value = true } } : null,
  can('documents.manage') ? { key: 'request', icon: 'tabler-file-import', run: () => go('people', { new: 'request' }) } : null,
  can('documents.manage') ? { key: 'campaign', icon: 'tabler-send', run: () => go('campaigns', { new: 'campaign' }) } : null,
  canSignatures.value && can('workforce_signature.request') ? { key: 'sign', icon: 'tabler-signature', run: () => { signOpen.value = true } } : null,
  can('payslips.import') ? { key: 'payslips', icon: 'tabler-cash', run: () => go('payslips') } : null,
].filter(Boolean))

// Liens directs (accueil, fiche) : `?new=deposit|sign` ouvre le formulaire, `&document=` présélectionne.
const signDocument = ref(null)
watch(() => route.query.new, v => {
  if (v !== 'deposit' && v !== 'sign') return
  if (v === 'deposit' && can('documents.manage')) depositOpen.value = true
  if (v === 'sign' && canSignatures.value && can('workforce_signature.request')) {
    signDocument.value = route.query.document ? Number(route.query.document) : null
    signOpen.value = true
  }
  const { new: _, document: __, ...query } = route.query

  router.replace({ query })
}, { immediate: true })

const onDeposited = async ({ subject }) => {
  if (subject === 'company') { await store.fetchCompanyDocuments(); if (activeTab.value !== 'company') go('company') }
  await Promise.allSettled([store.fetchCompliance(), can('documents.manage') ? store.fetchRequests({ silent: true }) : null])
}

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
    <SettingsReturnLink /> <!-- ADR-781 -->
    <!-- En-tête : titre · recherche · Nouveau · Outils -->
    <div class="d-flex align-center flex-wrap gap-3 mb-5">
      <h4 class="text-h4">
        {{ t('documents.title') }}
      </h4>
      <VSpacer />
      <AppTextField
        v-model="term"
        :placeholder="t('documents.search.placeholder')"
        prepend-inner-icon="tabler-search"
        clearable
        density="compact"
        style="max-inline-size: 320px; min-inline-size: 200px;"
        data-test="documents-search-input"
        @click:clear="closeSearch"
        @keydown.enter="searching = true"
      />
      <VBtn
        v-if="newActions.length"
        color="primary"
        prepend-icon="tabler-plus"
        data-test="documents-new"
      >
        {{ t('documents.new.label') }}
        <VMenu
          activator="parent"
          location="bottom end"
        >
          <VList min-width="260">
            <VListItem
              v-for="a in newActions"
              :key="a.key"
              :prepend-icon="a.icon"
              :data-test="`documents-new-${a.key}`"
              @click="a.run"
            >
              <VListItemTitle>{{ t(`documents.new.${a.key}`) }}</VListItemTitle>
            </VListItem>
          </VList>
        </VMenu>
      </VBtn>
      <VBtn
        v-if="toolViews.length || configModules.length"
        variant="tonal"
        color="secondary"
        prepend-icon="tabler-tool"
        data-test="documents-tools"
      >
        {{ t('documents.tools.label') }}
        <VMenu
          activator="parent"
          location="bottom end"
        >
          <VList min-width="300">
            <VListItem
              v-for="v in toolViews"
              :key="v.tab"
              :prepend-icon="v.icon"
              :to="{ name: 'company-documents-tab', params: { tab: v.tab } }"
              :data-test="`documents-tool-${v.tab}`"
            >
              <VListItemTitle>{{ v.title }}</VListItemTitle>
              <VListItemSubtitle>{{ v.hint }}</VListItemSubtitle>
            </VListItem>
            <template v-if="configModules.length">
              <VDivider class="my-1" />
              <VListSubheader>{{ t('documents.tools.configure') }}</VListSubheader>
              <VListItem
                v-for="m in configModules"
                :key="m.key"
                prepend-icon="tabler-adjustments"
                :to="`/company/modules/${m.key}`"
                :data-test="`documents-configure-${m.key}`"
              >
                <VListItemTitle>{{ m.name }}</VListItemTitle>
                <VListItemSubtitle>{{ t(`moduleSetup.status.${m.setup_status}`) }}</VListItemSubtitle>
              </VListItem>
            </template>
          </VList>
        </VMenu>
      </VBtn>
    </div>

    <!-- Loading -->
    <VSkeletonLoader
      v-if="pageLoading"
      type="heading, card, card"
    />
    <!-- Error -->
    <ErrorState
      v-else-if="pageError"
      :message="pageErrorMsg"
      @retry="pageRetry"
    />

    <!-- Recherche : remplace les vues tant qu'elle est ouverte -->
    <DocumentsSearch
      v-else-if="searching"
      :term="term"
      @close="closeSearch"
    />

    <!-- Content -->
    <template v-else>
      <!-- Vue avancée : un titre et un retour, pas un cinquième onglet -->
      <div
        v-if="currentTool"
        class="d-flex align-center gap-2 mb-4"
      >
        <VBtn
          variant="text"
          size="small"
          prepend-icon="tabler-arrow-left"
          :to="{ name: 'company-documents-tab', params: { tab: mainViews[0]?.tab || 'todo' } }"
          data-test="documents-tool-back"
        >
          {{ t('documents.title') }}
        </VBtn>
        <span class="text-h6">{{ currentTool.title }}</span>
      </div>
      <VTabs
        v-else
        :model-value="activeTab"
        class="mb-6"
      >
        <VTab
          v-for="item in mainViews"
          :key="item.tab"
          :value="item.tab"
          :to="{ name: 'company-documents-tab', params: { tab: item.tab } }"
          :data-test="`documents-view-${item.tab}`"
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
            inline
            class="ms-2"
          />
        </VTab>
      </VTabs>

      <DocumentsTodo v-if="activeTab === 'todo'" />

      <DocumentsVault
        v-else-if="activeTab === 'company'"
        @open-create-drawer="openCreateDrawer"
      />

      <!-- Collaborateurs : pièces demandées aux personnes (documents.manage) + documents générés (registre RH) -->
      <div v-else-if="activeTab === 'people'">
        <!-- ADR-822 — deux registres distincts, un à la fois : ce qui est DEMANDÉ aux personnes (en attente
             de dépôt ou de vérification) et ce que l'entreprise a GÉNÉRÉ (contrats, attestations, à signer). -->
        <VBtnToggle
          v-if="can('documents.manage') && canHr"
          v-model="peopleView"
          mandatory
          divided
          density="comfortable"
          variant="outlined"
          color="primary"
          class="mb-4"
          data-test="documents-people-switch"
        >
          <VBtn
            value="requests"
            prepend-icon="tabler-file-import"
            data-test="documents-people-requests"
          >
            {{ t('documents.peopleViews.requests') }}
          </VBtn>
          <VBtn
            value="generated"
            prepend-icon="tabler-file-certificate"
            data-test="documents-people-generated-switch"
          >
            {{ t('documents.peopleViews.generated') }}
          </VBtn>
        </VBtnToggle>
        <DocumentsRequests v-if="can('documents.manage') && (peopleView === 'requests' || !canHr)" />
        <div
          v-if="canHr && (peopleView === 'generated' || !can('documents.manage'))"
          data-test="documents-people-generated"
        >
          <HrDocuments
            embedded
            only="documents"
          />
        </div>
      </div>

      <SignaturePage
        v-else-if="activeTab === 'signatures' && canSignatures"
        embedded
      />

      <DocumentsCampaigns v-else-if="activeTab === 'campaigns'" />
      <DocumentsPayslipImport v-else-if="activeTab === 'payslips'" />
      <DocumentsCompliance v-else-if="activeTab === 'compliance'" />
      <HrDocuments
        v-else-if="activeTab === 'templates' && canTemplates"
        embedded
        only="templates"
      />
      <DocumentsSettings
        v-else-if="activeTab === 'settings'"
        @open-create-drawer="openCreateDrawer"
        @edit-custom-type="openEditDrawer"
      />
    </template>

    <CreateDocumentTypeDrawer
      v-model:is-drawer-open="isDrawerOpen"
      :edit-document="editDocument"
      @created="handleDrawerDone"
      @updated="handleDrawerDone"
    />
    <DocumentDepositDialog
      v-model="depositOpen"
      :subject="activeTab === 'company' ? 'company' : null"
      @deposited="onDeposited"
    />
    <SignatureWizard
      v-if="canSignatures"
      v-model:is-dialog-visible="signOpen"
      :preselected-document-id="signDocument"
      @created="go('signatures')"
    />
  </div>
</template>
