<script setup>
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.documents',
    navActiveLink: 'platform-documents',
  },
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { toast } = useAppToast()

const documentType = ref(null)
const isLoading = ref(true)
const saveLoading = ref(false)
const archiveLoading = ref(false)

// ─── Edit form (mutable fields) ─────────────────────
const form = ref({
  label: '',
  default_order: 0,
  validation_rules: {
    max_file_size_mb: 10,
    accepted_types: [],
    applicable_markets: [],
    required_by_jobdomains: [],
    required_by_modules: [],
    tags: [],
  },
})

// ─── Fetch ──────────────────────────────────────────
const fetchDetail = async () => {
  isLoading.value = true
  try {
    const data = await $platformApi(`/documents/${route.params.id}`)

    documentType.value = data.document_type
    form.value = {
      label: data.document_type.label,
      default_order: data.document_type.default_order || 0,
      validation_rules: {
        max_file_size_mb: data.document_type.validation_rules?.max_file_size_mb ?? 10,
        accepted_types: data.document_type.validation_rules?.accepted_types ?? [],
        applicable_markets: data.document_type.validation_rules?.applicable_markets ?? [],
        required_by_jobdomains: data.document_type.validation_rules?.required_by_jobdomains ?? [],
        required_by_modules: data.document_type.validation_rules?.required_by_modules ?? [],
        tags: data.document_type.validation_rules?.tags ?? [],
      },
    }
  }
  catch {
    toast(t('common.error'), 'error')
    router.push({ name: 'platform-documents' })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(fetchDetail)

// ─── Save ───────────────────────────────────────────
const handleSave = async () => {
  saveLoading.value = true
  try {
    const payload = {
      label: form.value.label,
      default_order: form.value.default_order,
      validation_rules: {
        max_file_size_mb: form.value.validation_rules.max_file_size_mb,
        accepted_types: form.value.validation_rules.accepted_types,
      },
    }

    // Only include non-empty targeting arrays
    const rules = form.value.validation_rules
    if (rules.applicable_markets?.length)
      payload.validation_rules.applicable_markets = rules.applicable_markets
    if (rules.required_by_jobdomains?.length)
      payload.validation_rules.required_by_jobdomains = rules.required_by_jobdomains
    if (rules.required_by_modules?.length)
      payload.validation_rules.required_by_modules = rules.required_by_modules
    if (rules.tags?.length)
      payload.validation_rules.tags = rules.tags

    const data = await $platformApi(`/documents/${route.params.id}`, {
      method: 'PUT',
      body: payload,
    })

    toast(data.message || t('platformDocumentTypes.updatedSuccess'), 'success')
    documentType.value = data.document_type
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    saveLoading.value = false
  }
}

// ─── Archive / Restore ──────────────────────────────
const handleArchive = async () => {
  archiveLoading.value = true
  try {
    await $platformApi(`/documents/${route.params.id}/archive`, { method: 'PUT' })
    toast(t('platformDocumentTypes.archivedSuccess'), 'success')
    await fetchDetail()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    archiveLoading.value = false
  }
}

const handleRestore = async () => {
  archiveLoading.value = true
  try {
    await $platformApi(`/documents/${route.params.id}/restore`, { method: 'PUT' })
    toast(t('platformDocumentTypes.restoredSuccess'), 'success')
    await fetchDetail()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    archiveLoading.value = false
  }
}

const formatSuggestions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx']
</script>

<template>
  <div>
    <!-- Back link -->
    <VBtn
      variant="text"
      size="small"
      prepend-icon="tabler-arrow-left"
      class="mb-4"
      :to="{ name: 'platform-documents' }"
    >
      {{ t('platformDocumentTypes.title') }}
    </VBtn>

    <div
      v-if="isLoading"
      class="text-center pa-12"
    >
      <VProgressCircular indeterminate />
    </div>

    <template v-else-if="documentType">
      <!-- Header -->
      <VCard class="mb-6">
        <VCardTitle class="d-flex align-center gap-2">
          <VIcon icon="tabler-file-text" />
          {{ documentType.label }}
          <code class="text-body-2 ms-2">({{ documentType.code }})</code>
          <VChip
            :color="documentType.is_archived ? 'warning' : 'success'"
            size="small"
            variant="tonal"
            class="ms-2"
          >
            {{ documentType.is_archived ? t('platformDocumentTypes.archived') : t('platformDocumentTypes.active') }}
          </VChip>
        </VCardTitle>
      </VCard>

      <VRow>
        <!-- Edit Form -->
        <VCol
          cols="12"
          md="8"
        >
          <VCard :title="t('platformDocumentTypes.editDocumentType')">
            <VCardText>
              <VForm @submit.prevent="handleSave">
                <VRow>
                  <!-- General -->
                  <VCol cols="12">
                    <h6 class="text-h6 mb-2">
                      {{ t('platformDocumentTypes.general') }}
                    </h6>
                  </VCol>

                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppTextField
                      :model-value="documentType.code"
                      :label="t('common.code')"
                      disabled
                      :hint="t('platformDocumentTypes.immutableHint')"
                      persistent-hint
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppTextField
                      :model-value="documentType.scope === 'company_user' ? t('platformDocumentTypes.scopeCompanyUser') : t('platformDocumentTypes.scopeCompany')"
                      :label="t('platformDocumentTypes.scope')"
                      disabled
                      :hint="t('platformDocumentTypes.immutableHint')"
                      persistent-hint
                    />
                  </VCol>

                  <VCol cols="8">
                    <AppTextField
                      v-model="form.label"
                      :label="t('platformDocumentTypes.label')"
                    />
                  </VCol>

                  <VCol cols="4">
                    <AppTextField
                      v-model.number="form.default_order"
                      :label="t('platformDocumentTypes.defaultOrder')"
                      type="number"
                      :min="0"
                    />
                  </VCol>

                  <!-- Validation Rules -->
                  <VCol cols="12">
                    <VDivider class="my-2" />
                    <h6 class="text-h6 mb-2 mt-4">
                      {{ t('platformDocumentTypes.validationRules') }}
                    </h6>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <AppTextField
                      v-model.number="form.validation_rules.max_file_size_mb"
                      :label="t('platformDocumentTypes.maxSize')"
                      type="number"
                      suffix="MB"
                      :min="1"
                      :max="50"
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="8"
                  >
                    <VCombobox
                      v-model="form.validation_rules.accepted_types"
                      :label="t('platformDocumentTypes.formats')"
                      :items="formatSuggestions"
                      multiple
                      chips
                      closable-chips
                      density="compact"
                    />
                  </VCol>

                  <!-- Targeting -->
                  <VCol cols="12">
                    <VDivider class="my-2" />
                    <h6 class="text-h6 mb-2 mt-4">
                      {{ t('platformDocumentTypes.targeting') }}
                    </h6>
                  </VCol>

                  <VCol
                    cols="12"
                    md="6"
                  >
                    <VCombobox
                      v-model="form.validation_rules.applicable_markets"
                      :label="t('platformDocumentTypes.applicableMarkets')"
                      multiple
                      chips
                      closable-chips
                      density="compact"
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="6"
                  >
                    <VCombobox
                      v-model="form.validation_rules.required_by_jobdomains"
                      :label="t('platformDocumentTypes.requiredByJobdomains')"
                      multiple
                      chips
                      closable-chips
                      density="compact"
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="6"
                  >
                    <VCombobox
                      v-model="form.validation_rules.required_by_modules"
                      :label="t('platformDocumentTypes.requiredByModules')"
                      multiple
                      chips
                      closable-chips
                      density="compact"
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="6"
                  >
                    <VCombobox
                      v-model="form.validation_rules.tags"
                      :label="t('platformDocumentTypes.tags')"
                      multiple
                      chips
                      closable-chips
                      density="compact"
                    />
                  </VCol>

                  <!-- Actions -->
                  <VCol cols="12">
                    <div class="d-flex gap-3 justify-end">
                      <VBtn
                        v-if="!documentType.is_archived"
                        variant="tonal"
                        color="warning"
                        :loading="archiveLoading"
                        prepend-icon="tabler-archive"
                        @click="handleArchive"
                      >
                        {{ t('platformDocumentTypes.archive') }}
                      </VBtn>
                      <VBtn
                        v-else
                        variant="tonal"
                        color="success"
                        :loading="archiveLoading"
                        prepend-icon="tabler-archive-off"
                        @click="handleRestore"
                      >
                        {{ t('platformDocumentTypes.restore') }}
                      </VBtn>
                      <VBtn
                        type="submit"
                        :loading="saveLoading"
                      >
                        {{ t('common.save') }}
                      </VBtn>
                    </div>
                  </VCol>
                </VRow>
              </VForm>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Usage Stats -->
        <VCol
          cols="12"
          md="4"
        >
          <VCard :title="t('platformDocumentTypes.usageStats')">
            <VCardText>
              <VList density="compact">
                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-building"
                      size="20"
                    />
                  </template>
                  <VListItemTitle>{{ t('platformDocumentTypes.activationsCount') }}</VListItemTitle>
                  <template #append>
                    <strong>{{ documentType.activations_count }}</strong>
                  </template>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-users"
                      size="20"
                    />
                  </template>
                  <VListItemTitle>{{ t('platformDocumentTypes.memberDocumentsCount') }}</VListItemTitle>
                  <template #append>
                    <strong>{{ documentType.member_documents_count }}</strong>
                  </template>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-file"
                      size="20"
                    />
                  </template>
                  <VListItemTitle>{{ t('platformDocumentTypes.companyDocumentsCount') }}</VListItemTitle>
                  <template #append>
                    <strong>{{ documentType.company_documents_count }}</strong>
                  </template>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-clipboard"
                      size="20"
                    />
                  </template>
                  <VListItemTitle>{{ t('platformDocumentTypes.requestsCount') }}</VListItemTitle>
                  <template #append>
                    <strong>{{ documentType.requests_count }}</strong>
                  </template>
                </VListItem>
              </VList>

              <template v-if="documentType.jobdomain_presets?.length">
                <VDivider class="my-4" />
                <h6 class="text-subtitle-2 mb-2">
                  {{ t('platformDocumentTypes.usedInJobdomains') }}
                </h6>
                <div class="d-flex flex-wrap gap-1">
                  <VChip
                    v-for="jd in documentType.jobdomain_presets"
                    :key="jd.id"
                    size="small"
                    variant="tonal"
                    color="primary"
                  >
                    {{ jd.label }}
                  </VChip>
                </div>
              </template>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
