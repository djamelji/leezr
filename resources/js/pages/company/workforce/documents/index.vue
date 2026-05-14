<script setup>
import { useAppToast } from '@/composables/useAppToast'
import { useWorkforceDocumentsStore } from '@/modules/company/workforce/workforce-documents.store'
import PayslipActionCards from './_PayslipActionCards.vue'
import GenerateDocumentDrawer from './_GenerateDocumentDrawer.vue'

definePage({ meta: { module: 'workforce_documents', permission: 'workforce_documents.view' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const store = useWorkforceDocumentsStore()

// ── Table state ──────────────────────────────────────────────
const options = ref({ page: 1, itemsPerPage: 15 })
const loading = ref(false)

// ── Filters ──────────────────────────────────────────────────
const subjectTypeFilter = ref(null)
const signatureStatusFilter = ref(null)
const templateCodeFilter = ref('')

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

const onDraftsGenerated = () => {
  toast(t('workforceDocuments.success.draftsGenerated'), 'success')
  refreshAll()
}

const onOfficialGenerated = () => {
  toast(t('workforceDocuments.success.officialGenerated'), 'success')
  refreshAll()
}

watch([subjectTypeFilter, signatureStatusFilter, templateCodeFilter], () => {
  options.value.page = 1
  fetchData()
}, { debounce: 300 })

watch(options, fetchData, { deep: true })

onMounted(() => {
  fetchData()
  fetchStats()
})

const stats = computed(() => store.statistics)

// ── Drawer ───────────────────────────────────────────────────
const isDrawerOpen = ref(false)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('workforceDocuments.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('workforceDocuments.subtitleFull') }}
        </p>
      </div>
      <VBtn
        v-can="'workforce_documents.generate'"
        color="primary"
        prepend-icon="tabler-file-plus"
        @click="isDrawerOpen = true"
      >
        {{ $t('workforceDocuments.generateDocument') }}
      </VBtn>
    </div>

    <!-- Payslip Action Cards -->
    <PayslipActionCards
      :store="store"
      @drafts-generated="onDraftsGenerated"
      @official-generated="onOfficialGenerated"
    />

    <!-- Stats Cards -->
    <VRow
      v-if="stats"
      class="mb-6"
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
      </VCardText>

      <!-- Table -->
      <VDataTableServer
        v-model:options="options"
        :headers="headers"
        :items="store.documents"
        :items-length="store.totalDocuments"
        :loading="loading"
        class="text-no-wrap"
      >
        <!-- Template code -->
        <template #item.template_code="{ item }">
          <span class="font-weight-medium">
            {{ item.template_code }}
          </span>
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
              :href="item.file_path"
              target="_blank"
            >
              <VIcon icon="tabler-eye" />
            </VBtn>
            <VBtn
              v-if="item.file_path"
              icon
              variant="text"
              size="small"
              :title="$t('workforceDocuments.download')"
              :href="item.file_path"
              download
            >
              <VIcon icon="tabler-download" />
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

    <!-- Generate Document Drawer -->
    <GenerateDocumentDrawer
      v-model="isDrawerOpen"
      :store="store"
      @generated="onDocumentGenerated"
    />
  </div>
</template>
