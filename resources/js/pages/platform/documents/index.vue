<script setup>
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.documents',
  },
})

const { t } = useI18n()
const router = useRouter()
const { toast } = useAppToast()

const documentTypes = ref([])
const isLoading = ref(true)
const syncLoading = ref(false)
const scopeTab = ref('all')

// ─── Fetch ──────────────────────────────────────────
const fetchDocumentTypes = async () => {
  isLoading.value = true
  try {
    const data = await $platformApi('/documents')

    documentTypes.value = data.document_types || []
  }
  finally {
    isLoading.value = false
  }
}

onMounted(fetchDocumentTypes)

// ─── Computed filtered ──────────────────────────────
const filteredTypes = computed(() => {
  if (scopeTab.value === 'all')
    return documentTypes.value

  return documentTypes.value.filter(dt => dt.scope === scopeTab.value)
})

// ─── Table headers ──────────────────────────────────
const headers = computed(() => [
  { title: t('common.code'), key: 'code', width: '180px' },
  { title: t('platformDocumentTypes.label'), key: 'label' },
  { title: t('platformDocumentTypes.scope'), key: 'scope', width: '140px' },
  { title: t('platformDocumentTypes.maxSize'), key: 'max_file_size_mb', width: '100px', align: 'center' },
  { title: t('platformDocumentTypes.formats'), key: 'accepted_types', width: '160px' },
  { title: t('common.status'), key: 'status', width: '110px', align: 'center' },
  { title: t('common.actions'), key: 'actions', width: '160px', align: 'center', sortable: false },
])

// ─── Sync ───────────────────────────────────────────
const handleSync = async () => {
  syncLoading.value = true
  try {
    const data = await $platformApi('/documents/sync', { method: 'POST' })

    documentTypes.value = data.document_types || []
    toast(t('platformDocumentTypes.syncedSuccess'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformDocumentTypes.syncFailed'), 'error')
  }
  finally {
    syncLoading.value = false
  }
}

// ─── Archive / Restore ──────────────────────────────
const archiveLoading = ref(null)

const handleArchive = async dt => {
  archiveLoading.value = dt.id
  try {
    await $platformApi(`/documents/${dt.id}/archive`, { method: 'PUT' })
    toast(t('platformDocumentTypes.archivedSuccess'), 'success')
    await fetchDocumentTypes()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    archiveLoading.value = null
  }
}

const handleRestore = async dt => {
  archiveLoading.value = dt.id
  try {
    await $platformApi(`/documents/${dt.id}/restore`, { method: 'PUT' })
    toast(t('platformDocumentTypes.restoredSuccess'), 'success')
    await fetchDocumentTypes()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    archiveLoading.value = null
  }
}

// ─── Create dialog ──────────────────────────────────
const isCreateDialogOpen = ref(false)
const createLoading = ref(false)

const createForm = ref({
  code: '',
  label: '',
  scope: 'company_user',
  validation_rules: {
    max_file_size_mb: 10,
    accepted_types: ['pdf', 'jpg', 'jpeg', 'png'],
    applicable_markets: [],
    required_by_jobdomains: [],
    required_by_modules: [],
    tags: [],
  },
})

const resetCreateForm = () => {
  createForm.value = {
    code: '',
    label: '',
    scope: 'company_user',
    validation_rules: {
      max_file_size_mb: 10,
      accepted_types: ['pdf', 'jpg', 'jpeg', 'png'],
      applicable_markets: [],
      required_by_jobdomains: [],
      required_by_modules: [],
      tags: [],
    },
  }
}

const handleCreate = async () => {
  createLoading.value = true
  try {
    const payload = {
      code: createForm.value.code,
      label: createForm.value.label,
      scope: createForm.value.scope,
      validation_rules: {
        max_file_size_mb: createForm.value.validation_rules.max_file_size_mb,
        accepted_types: createForm.value.validation_rules.accepted_types,
      },
    }

    // Only include non-empty targeting arrays
    const rules = createForm.value.validation_rules
    if (rules.applicable_markets?.length)
      payload.validation_rules.applicable_markets = rules.applicable_markets
    if (rules.required_by_jobdomains?.length)
      payload.validation_rules.required_by_jobdomains = rules.required_by_jobdomains
    if (rules.required_by_modules?.length)
      payload.validation_rules.required_by_modules = rules.required_by_modules
    if (rules.tags?.length)
      payload.validation_rules.tags = rules.tags

    const data = await $platformApi('/documents', {
      method: 'POST',
      body: payload,
    })

    toast(data.message || t('platformDocumentTypes.createdSuccess'), 'success')
    isCreateDialogOpen.value = false
    resetCreateForm()

    router.push({ name: 'platform-documents-id', params: { id: data.document_type.id } })
  }
  catch (error) {
    toast(error?.data?.message || t('platformDocumentTypes.createFailed'), 'error')
  }
  finally {
    createLoading.value = false
  }
}

const scopeOptions = computed(() => [
  { title: t('platformDocumentTypes.scopeCompanyUser'), value: 'company_user' },
  { title: t('platformDocumentTypes.scopeCompany'), value: 'company' },
])

const formatSuggestions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx']
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center flex-wrap gap-2">
        <VIcon
          icon="tabler-file-text"
          class="me-2"
        />
        {{ t('platformDocumentTypes.title') }}
        <VSpacer />
        <VBtn
          size="small"
          variant="tonal"
          color="secondary"
          :loading="syncLoading"
          prepend-icon="tabler-refresh"
          @click="handleSync"
        >
          {{ t('platformDocumentTypes.syncFromCatalog') }}
        </VBtn>
        <VBtn
          size="small"
          prepend-icon="tabler-plus"
          @click="isCreateDialogOpen = true"
        >
          {{ t('platformDocumentTypes.createDocumentType') }}
        </VBtn>
      </VCardTitle>

      <VCardText class="pb-0">
        <VTabs v-model="scopeTab">
          <VTab value="all">
            {{ t('common.all') }}
          </VTab>
          <VTab value="company_user">
            {{ t('platformDocumentTypes.scopeCompanyUser') }}
          </VTab>
          <VTab value="company">
            {{ t('platformDocumentTypes.scopeCompany') }}
          </VTab>
        </VTabs>
      </VCardText>

      <VDataTable
        :headers="headers"
        :items="filteredTypes"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <template #item.code="{ item }">
          <code class="text-body-2">{{ item.code }}</code>
        </template>

        <template #item.scope="{ item }">
          <VChip
            size="small"
            variant="tonal"
            :color="item.scope === 'company_user' ? 'primary' : 'info'"
          >
            {{ item.scope === 'company_user' ? t('platformDocumentTypes.scopeCompanyUser') : t('platformDocumentTypes.scopeCompany') }}
          </VChip>
        </template>

        <template #item.max_file_size_mb="{ item }">
          {{ item.validation_rules?.max_file_size_mb || '—' }} MB
        </template>

        <template #item.accepted_types="{ item }">
          <div class="d-flex gap-1 flex-wrap">
            <VChip
              v-for="fmt in (item.validation_rules?.accepted_types || [])"
              :key="fmt"
              size="x-small"
              variant="tonal"
            >
              {{ fmt.toUpperCase() }}
            </VChip>
          </div>
        </template>

        <template #item.status="{ item }">
          <VChip
            :color="item.is_archived ? 'warning' : 'success'"
            size="small"
            variant="tonal"
          >
            {{ item.is_archived ? t('platformDocumentTypes.archived') : t('platformDocumentTypes.active') }}
          </VChip>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-center">
            <VBtn
              size="small"
              variant="tonal"
              :to="{ name: 'platform-documents-id', params: { id: item.id } }"
            >
              {{ t('common.manage') }}
            </VBtn>
            <VBtn
              v-if="!item.is_archived"
              icon
              variant="text"
              size="small"
              color="warning"
              :loading="archiveLoading === item.id"
              @click="handleArchive(item)"
            >
              <VIcon icon="tabler-archive" />
              <VTooltip
                activator="parent"
                location="top"
              >
                {{ t('platformDocumentTypes.archive') }}
              </VTooltip>
            </VBtn>
            <VBtn
              v-else
              icon
              variant="text"
              size="small"
              color="success"
              :loading="archiveLoading === item.id"
              @click="handleRestore(item)"
            >
              <VIcon icon="tabler-archive-off" />
              <VTooltip
                activator="parent"
                location="top"
              >
                {{ t('platformDocumentTypes.restore') }}
              </VTooltip>
            </VBtn>
          </div>
        </template>

        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            {{ t('platformDocumentTypes.noDocumentTypes') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- ─── Create Dialog ──────────────────────────────── -->
    <VDialog
      v-model="isCreateDialogOpen"
      max-width="600"
    >
      <VCard :title="t('platformDocumentTypes.createDocumentType')">
        <VCardText>
          <VForm @submit.prevent="handleCreate">
            <VRow>
              <VCol cols="12">
                <AppTextField
                  v-model="createForm.code"
                  :label="t('common.code')"
                  placeholder="e.g. work_permit"
                  :hint="t('platformDocumentTypes.codeHint')"
                  persistent-hint
                />
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="createForm.label"
                  :label="t('platformDocumentTypes.label')"
                  placeholder="e.g. Work Permit"
                />
              </VCol>

              <VCol cols="12">
                <AppSelect
                  v-model="createForm.scope"
                  :label="t('platformDocumentTypes.scope')"
                  :items="scopeOptions"
                />
              </VCol>

              <VCol cols="6">
                <AppTextField
                  v-model.number="createForm.validation_rules.max_file_size_mb"
                  :label="t('platformDocumentTypes.maxSize')"
                  type="number"
                  suffix="MB"
                  :min="1"
                  :max="50"
                />
              </VCol>

              <VCol cols="6">
                <VCombobox
                  v-model="createForm.validation_rules.accepted_types"
                  :label="t('platformDocumentTypes.formats')"
                  :items="formatSuggestions"
                  multiple
                  chips
                  closable-chips
                  density="compact"
                />
              </VCol>

              <VCol cols="12">
                <div class="d-flex gap-3 justify-end">
                  <VBtn
                    variant="tonal"
                    color="secondary"
                    @click="isCreateDialogOpen = false"
                  >
                    {{ t('common.cancel') }}
                  </VBtn>
                  <VBtn
                    type="submit"
                    :loading="createLoading"
                  >
                    {{ t('common.create') }}
                  </VBtn>
                </div>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>
