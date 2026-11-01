<script setup>
import { useAppToast } from '@/composables/useAppToast'
import { useWorkforceDocumentsStore } from '@/modules/company/workforce/workforce-documents.store'
import { useCan } from '@/composables/useCan'
import GenerateDocumentDrawer from './_GenerateDocumentDrawer.vue'
import SignatureWizard from '@/pages/company/workforce/signature/_SignatureWizard.vue'
// PRODUCT-UX-2 (LOT A) — espace Documents UNIFIÉ : le registre (ce fichier) + la bibliothèque de
// modèles + les signatures, montés en onglets. Chaque vue réutilise sa page existante (logique,
// store, permissions intacts) via la prop `embedded`. Gating par permission, comme la nav d'origine.
import ContractTemplates from '@/pages/company/workforce/contract/templates.vue'
import SignaturePage from '@/pages/company/workforce/signature/index.vue'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import DocumentDetailDrawer from './_DocumentDetailDrawer.vue'

definePage({ meta: { module: 'workforce_documents', permission: 'workforce_documents.view' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const { can } = useCan()
const store = useWorkforceDocumentsStore()

// W-SIGNATURE-1 : demander une signature depuis la page Documents (assistant prérempli).
const sigWizardOpen = ref(false)
const sigDocId = ref(null)
const requestSignature = item => { sigDocId.value = item.id; sigWizardOpen.value = true }

// ── Download (ADR-634) — route authentifiée, jamais file_path brut ──
const downloadDocument = async (item, { save = true } = {}) => {
  try {
    const blob = await store.downloadDocument(item.id)
    const url = URL.createObjectURL(blob)
    if (save) {
      const a = document.createElement('a')

      a.href = url
      a.download = `${item.template_code || 'document'}-${item.id}.pdf`
      a.click()
    } else {
      window.open(url, '_blank')
    }
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  } catch {
    // erreur gérée par $api
  }
}

// ── Table state ──────────────────────────────────────────────
const options = ref({ page: 1, itemsPerPage: 15 })
const loading = ref(false)

// ── Filters ──────────────────────────────────────────────────
const subjectTypeFilter = ref(null)
const signatureStatusFilter = ref(null)
const templateCodeFilter = ref('')
// PRODUCT-UX-2 (LOT A2) — registre transversal.
const employeeFilter = ref(null)
const searchFilter = ref('')
const dateFrom = ref('')
const dateTo = ref('')

// Liste légère des collaborateurs pour l'autocomplete (réutilise le store Employees existant).
const employeesStore = useEmployeesStore()
const employeeOptions = computed(() => (employeesStore.employees || []).map(e => ({ title: `${e.first_name} ${e.last_name}`, value: e.id })))

const subjectTypeOptions = [
  { title: t('workforceDocuments.subjects.payrollLine'), value: 'payroll_line' },
  { title: t('workforceDocuments.subjects.employee'), value: 'employee' },
  { title: t('workforceDocuments.subjects.contract'), value: 'contract' },
  { title: t('workforceDocuments.subjects.leaveRequest'), value: 'leave_request' },
  { title: t('workforceDocuments.subjects.payrollRun'), value: 'payroll_run' },
]

const signatureStatusOptions = [
  { title: t('workforceDocuments.signatureStatuses.none'), value: 'none' },
  { title: t('workforceDocuments.signatureStatuses.pending'), value: 'pending' },
  { title: t('workforceDocuments.signatureStatuses.signed'), value: 'signed' },
  { title: t('workforceDocuments.signatureStatuses.declined'), value: 'declined' },
]

// ── Table headers ────────────────────────────────────────────
const headers = computed(() => [
  { title: t('workforceDocuments.columns.templateCode'), key: 'template_code', sortable: false },
  { title: t('workforceDocuments.columns.collaborator'), key: 'collaborator', sortable: false },
  { title: t('workforceDocuments.columns.subject'), key: 'subject', sortable: false },
  { title: t('workforceDocuments.columns.signatureStatus'), key: 'signature_status', sortable: false, width: 140 },
  { title: t('workforceDocuments.columns.generatedAt'), key: 'generated_at', sortable: false, width: 160 },
  { title: t('workforceDocuments.columns.generatedBy'), key: 'generated_by_name', sortable: false, width: 150 },
  { title: t('workforceDocuments.columns.actions'), key: 'actions', sortable: false, width: 100 },
])

// ── Helpers ──────────────────────────────────────────────────
function formatSubject(type, id) {
  const labels = {
    payroll_line: t('workforceDocuments.subjects.payrollLine'),
    employee: t('workforceDocuments.subjects.employee'),
    contract: t('workforceDocuments.subjects.contract'),
    leave_request: t('workforceDocuments.subjects.leaveRequest'),
    payroll_run: t('workforceDocuments.subjects.payrollRun'),
  }

  return `${labels[type] || type} #${id}`
}

const signatureColor = status => {
  const map = { none: 'secondary', pending: 'warning', signed: 'success', declined: 'error' }

  return map[status] || 'secondary'
}

const signatureLabel = status => {
  const map = {
    none: t('workforceDocuments.signatureStatuses.none'),
    pending: t('workforceDocuments.signatureStatuses.pending'),
    signed: t('workforceDocuments.signatureStatuses.signed'),
    declined: t('workforceDocuments.signatureStatuses.declined'),
  }

  return map[status] || status
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(locale.value, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

// ── Data fetching ────────────────────────────────────────────
const fetchData = async () => {
  loading.value = true
  try {
    await store.fetchDocuments({
      subjectType: subjectTypeFilter.value || undefined,
      signatureStatus: signatureStatusFilter.value || undefined,
      templateCode: templateCodeFilter.value || undefined,
      employeeId: employeeFilter.value || undefined,
      search: searchFilter.value || undefined,
      dateFrom: dateFrom.value || undefined,
      dateTo: dateTo.value || undefined,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
    })
  } finally {
    loading.value = false
  }
}

const fetchStats = async () => {
  try {
    await store.fetchStatistics()
  } catch {
    // Statistics are optional
  }
}

const refreshAll = () => {
  fetchData()
  fetchStats()
}

const onDocumentGenerated = () => {
  toast(t('workforceDocuments.success.generated'), 'success')
  refreshAll()
}

watchDebounced([subjectTypeFilter, signatureStatusFilter, templateCodeFilter, employeeFilter, searchFilter, dateFrom, dateTo], () => {
  options.value.page = 1
  fetchData()
}, { debounce: 350 })

watch(options, fetchData, { deep: true })

onMounted(() => {
  fetchData()
  fetchStats()
  employeesStore.fetchEmployees({ perPage: 200 }).catch(() => {})
})

const stats = computed(() => store.statistics)

// ── Panneau documentaire unifié (cycle de vie dans un seul contexte) ──
const detailDoc = ref(null)
const detailOpen = ref(false)
const openDetail = row => { detailDoc.value = row; detailOpen.value = true }
// Depuis le panneau : suivre la signature = basculer sur l'onglet Signatures (reste dans l'espace).
const goToSignatures = () => { detailOpen.value = false; activeTab.value = 'signatures' }
const requestSignatureFor = row => { detailOpen.value = false; requestSignature(row) }

// ── Drawer ───────────────────────────────────────────────────
const isDrawerOpen = ref(false)

// ── Onglets de l'espace Documents (deep-link ?tab=) ──────────
const route = useRoute()
const router = useRouter()
const canTemplates = computed(() => can('workforce_contract.view'))
const canSignatures = computed(() => can('workforce_signature.view'))
const activeTab = ref(['templates', 'signatures'].includes(route.query.tab) ? route.query.tab : 'documents')
watch(activeTab, v => {
  const q = { ...route.query }
  if (v === 'documents') delete q.tab
  else q.tab = v
  router.replace({ query: q })
})
</script>

<template>
  <div>
    <!-- Header de l'espace Documents unifié -->
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('workforceDocuments.spaceTitle') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('workforceDocuments.spaceSubtitle') }}
        </p>
      </div>
      <VBtn
        v-if="activeTab === 'documents' && can('workforce_documents.generate')"
        color="primary"
        prepend-icon="tabler-file-plus"
        @click="isDrawerOpen = true"
      >
        {{ $t('workforceDocuments.generateDocument') }}
      </VBtn>
    </div>

    <!-- Onglets : Registre · Modèles · Signatures (chaque onglet gated par permission) -->
    <VTabs v-model="activeTab" class="mb-5">
      <VTab value="documents" prepend-icon="tabler-files" data-test="doc-tab-documents">
        {{ $t('workforceDocuments.tabs.documents') }}
      </VTab>
      <VTab v-if="canTemplates" value="templates" prepend-icon="tabler-file-text" data-test="doc-tab-templates">
        {{ $t('workforceDocuments.tabs.templates') }}
      </VTab>
      <VTab v-if="canSignatures" value="signatures" prepend-icon="tabler-signature" data-test="doc-tab-signatures">
        {{ $t('workforceDocuments.tabs.signatures') }}
      </VTab>
    </VTabs>

    <VWindow v-model="activeTab" :touch="false">
      <VWindowItem value="documents">

    <!-- PRODUCT-UX-AUDIT-1 (P0, dédoublonnage C) : la génération de bulletins n'existe plus ici —
         elle appartient au cycle de paie (évite la même action sur 2 pages). Documents RH = registre. -->
    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-6"
      data-test="payslip-generation-moved"
    >
      <div class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span>{{ $t('workforceDocuments.payslipMovedHint') }}</span>
        <VBtn
          size="small"
          variant="tonal"
          append-icon="tabler-arrow-right"
          :to="{ name: 'company-workforce-payroll' }"
        >
          {{ $t('workforceDocuments.goToPayroll') }}
        </VBtn>
      </div>
    </VAlert>

    <!-- Stats Cards -->
    <VRow
      v-if="stats"
      class="card-grid card-grid-xs mb-6"
    >
      <VCol
        cols="12"
        sm="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="primary"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-files"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('workforceDocuments.stats.totalDocuments') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.total ?? 0 }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        sm="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="info"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-file-pencil"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('workforceDocuments.stats.payslipDrafts') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.payslip_drafts ?? 0 }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        sm="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="success"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-certificate"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('workforceDocuments.stats.officialPayslips') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.official_payslips ?? 0 }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters + Table -->
    <VCard>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="subjectTypeFilter"
              :items="subjectTypeOptions"
              :placeholder="$t('workforceDocuments.filters.subjectType')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="signatureStatusFilter"
              :items="signatureStatusOptions"
              :placeholder="$t('workforceDocuments.filters.signatureStatus')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model="templateCodeFilter"
              :placeholder="$t('workforceDocuments.filters.templateCode')"
              clearable
              density="compact"
              prepend-inner-icon="tabler-search"
            />
          </VCol>
        </VRow>

        <!-- PRODUCT-UX-2 (LOT A2) — registre transversal : collaborateur / recherche / période -->
        <VRow>
          <VCol cols="12" md="4">
            <AppAutocomplete
              v-model="employeeFilter"
              :items="employeeOptions"
              :placeholder="$t('workforceDocuments.filters.employee')"
              clearable
              density="compact"
              prepend-inner-icon="tabler-user"
              data-test="doc-filter-employee"
            />
          </VCol>
          <VCol cols="12" md="4">
            <AppTextField
              v-model="searchFilter"
              :placeholder="$t('workforceDocuments.filters.search')"
              clearable
              density="compact"
              prepend-inner-icon="tabler-search"
              data-test="doc-filter-search"
            />
          </VCol>
          <VCol cols="6" md="2">
            <AppTextField v-model="dateFrom" type="date" :label="$t('workforceDocuments.filters.dateFrom')" density="compact" clearable />
          </VCol>
          <VCol cols="6" md="2">
            <AppTextField v-model="dateTo" type="date" :label="$t('workforceDocuments.filters.dateTo')" density="compact" clearable />
          </VCol>
        </VRow>
      </VCardText>

      <!-- Table -->
      <VDataTableServer
        v-model:options="options"
        :headers="headers"
        :items="store.documents"
        :items-length="store.totalDocuments"
        :loading="loading"
        class="text-no-wrap cursor-pointer"
        hover
        @click:row="(_, { item }) => openDetail(item)"
      >
        <!-- Template code -->
        <template #item.template_code="{ item }">
          <span class="font-weight-medium">
            {{ item.template_code }}
          </span>
        </template>

        <!-- Collaborateur (résolu transversalement) -->
        <template #item.collaborator="{ item }">
          <span v-if="item.employee_name" class="d-flex align-center gap-2">
            <VIcon icon="tabler-user" size="16" class="text-medium-emphasis" />{{ item.employee_name }}
          </span>
          <span v-else class="text-disabled">—</span>
        </template>

        <!-- Subject -->
        <template #item.subject="{ item }">
          {{ formatSubject(item.subject_type, item.subject_id) }}
        </template>

        <!-- Signature status -->
        <template #item.signature_status="{ item }">
          <VChip
            :color="signatureColor(item.signature_status)"
            size="small"
            variant="tonal"
          >
            {{ signatureLabel(item.signature_status) }}
          </VChip>
        </template>

        <!-- Generated at -->
        <template #item.generated_at="{ item }">
          {{ formatDate(item.generated_at) }}
        </template>

        <!-- Generated by -->
        <template #item.generated_by_name="{ item }">
          {{ item.generated_by_name ?? '—' }}
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn
              icon
              variant="text"
              size="small"
              :title="$t('workforceDocuments.preview')"
              @click.stop="downloadDocument(item, { save: false })"
            >
              <VIcon icon="tabler-eye" />
            </VBtn>
            <VBtn
              v-if="item.file_path"
              icon
              variant="text"
              size="small"
              :title="$t('workforceDocuments.download')"
              @click.stop="downloadDocument(item, { save: true })"
            >
              <VIcon icon="tabler-download" />
            </VBtn>
            <VBtn
              v-if="item.file_path && item.signature_status !== 'signed' && can('workforce_signature.request')"
              icon
              variant="text"
              size="small"
              color="primary"
              :title="$t('signature.actions.new')"
              @click.stop="requestSignature(item)"
            >
              <VIcon icon="tabler-signature" />
            </VBtn>
          </div>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-file-text"
              size="48"
              color="secondary"
              class="mb-4"
            />
            <h6 class="text-h6 mb-2">
              {{ $t('workforceDocuments.emptyState.title') }}
            </h6>
            <p class="text-body-1 text-medium-emphasis mb-4">
              {{ $t('workforceDocuments.emptyState.description') }}
            </p>
            <VBtn
              v-can="'workforce_documents.generate'"
              color="primary"
              prepend-icon="tabler-file-plus"
              @click="isDrawerOpen = true"
            >
              {{ $t('workforceDocuments.generateDocument') }}
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>
      </VWindowItem>

      <!-- Modèles de contrat (réutilise la page existante, en-tête masqué) -->
      <VWindowItem v-if="canTemplates" value="templates">
        <ContractTemplates v-if="activeTab === 'templates'" embedded />
      </VWindowItem>

      <!-- Signatures (réutilise la page existante, en-tête masqué) -->
      <VWindowItem v-if="canSignatures" value="signatures">
        <SignaturePage v-if="activeTab === 'signatures'" embedded />
      </VWindowItem>
    </VWindow>

    <!-- Generate Document Drawer -->
    <GenerateDocumentDrawer
      v-model="isDrawerOpen"
      :store="store"
      @generated="onDocumentGenerated"
    />

    <!-- W-SIGNATURE-1 : assistant de demande de signature prérempli depuis un document -->
    <SignatureWizard
      v-model:is-dialog-visible="sigWizardOpen"
      :preselected-document-id="sigDocId"
      @created="() => { sigWizardOpen = false; toast(t('signature.toasts.created'), 'success'); refreshAll() }"
    />

    <!-- PRODUCT-UX-2 (LOT A2) : panneau documentaire unifié (cycle de vie dans un seul contexte) -->
    <DocumentDetailDrawer
      v-model="detailOpen"
      :document="detailDoc"
      :can-request="can('workforce_signature.request')"
      @preview="d => downloadDocument(d, { save: false })"
      @download="d => downloadDocument(d, { save: true })"
      @request-signature="requestSignatureFor"
      @go-signatures="goToSignatures"
      @open-fiche="d => router.push(`/company/workforce/employees/${d.employee_id}`)"
    />
  </div>
</template>
