<script setup>
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  isDrawerOpen: {
    type: Boolean,
    required: true,
  },
  editDocument: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['update:isDrawerOpen', 'created', 'updated'])

const { t } = useI18n()
const settingsStore = useCompanySettingsStore()
const documentsStore = useCompanyDocumentsStore()
const { toast } = useAppToast()

const isLoading = ref(false)
const showAdvanced = ref(false)

const isEditMode = computed(() => !!props.editDocument)

const scopeOptions = [
  { title: t('documents.scopeMember'), value: 'company_user' },
  { title: t('documents.scopeCompany'), value: 'company' },
]

const acceptedTypeOptions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']

const defaultForm = () => ({
  label: '',
  scope: 'company_user',
  max_file_size_mb: 10,
  accepted_types: ['pdf', 'jpg', 'jpeg', 'png'],
  order: 0,
  required: false,
  requires_expiration: false,
})

const form = ref(defaultForm())

// Pre-fill form when editDocument changes
watch(() => props.editDocument, doc => {
  if (doc) {
    form.value = {
      label: doc.label,
      scope: doc.scope,
      max_file_size_mb: doc.max_file_size_mb,
      accepted_types: [...doc.accepted_types],
      order: doc.order ?? 0,
      required: doc.required_override ?? false,
      requires_expiration: doc.requires_expiration ?? false,
    }
    showAdvanced.value = false
  }
  else {
    form.value = defaultForm()
    showAdvanced.value = false
  }
})

const handleClose = () => {
  emit('update:isDrawerOpen', false)
  form.value = defaultForm()
  isLoading.value = false
  showAdvanced.value = false
}

const handleSubmit = async () => {
  isLoading.value = true

  try {
    if (isEditMode.value) {
      await documentsStore.updateCustomDocumentType(props.editDocument.code, {
        label: form.value.label,
        max_file_size_mb: form.value.max_file_size_mb,
        accepted_types: form.value.accepted_types,
        requires_expiration: form.value.requires_expiration,
      })
      toast(t('documents.customTypeUpdated'), 'success')
      emit('updated')
    }
    else {
      await settingsStore.createCustomDocumentType({ ...form.value })
      toast(t('documents.customTypeCreated'), 'success')
      emit('created')
    }
    handleClose()
    documentsStore.fetchDocumentActivations()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
    isLoading.value = false
  }
}
</script>

<template>
  <VNavigationDrawer
    temporary
    :width="400"
    location="end"
    class="scrollable-content"
    :model-value="props.isDrawerOpen"
    @update:model-value="handleClose"
  >
    <AppDrawerHeaderSection
      :title="isEditMode ? t('documents.editCustomDocument') : t('documents.createCustomDocument')"
      @cancel="handleClose"
    />

    <VDivider />

    <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
      <VCard flat>
        <VCardText>
          <VAlert
            :type="isEditMode ? 'warning' : 'info'"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ isEditMode ? t('documents.drawerEditHint') : t('documents.drawerCreateHint') }}
          </VAlert>

          <VForm @submit.prevent="handleSubmit">
            <VRow>
              <!-- Document name -->
              <VCol cols="12">
                <AppTextField
                  v-model="form.label"
                  :label="t('documents.documentLabel')"
                  :placeholder="t('documents.documentLabelPlaceholder')"
                />
              </VCol>

              <!-- Scope -->
              <VCol cols="12">
                <AppSelect
                  v-model="form.scope"
                  :label="t('documents.drawerScopeLabel')"
                  :items="scopeOptions"
                  :hint="form.scope === 'company_user' ? t('documents.scopeHintMember') : t('documents.scopeHintCompany')"
                  persistent-hint
                  :disabled="isEditMode"
                />
              </VCol>

              <!-- Requires expiration -->
              <VCol cols="12">
                <VSwitch
                  v-model="form.requires_expiration"
                  :label="t('documents.requiresExpiration')"
                  :hint="t('documents.requiresExpirationHint')"
                  persistent-hint
                />
              </VCol>

              <!-- Advanced settings toggle -->
              <VCol cols="12">
                <VBtn
                  variant="text"
                  color="secondary"
                  size="small"
                  :prepend-icon="showAdvanced ? 'tabler-chevron-up' : 'tabler-chevron-down'"
                  @click="showAdvanced = !showAdvanced"
                >
                  {{ t('documents.advancedSettings') }}
                </VBtn>
              </VCol>

              <!-- Advanced settings (collapsed by default) -->
              <template v-if="showAdvanced">
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model.number="form.max_file_size_mb"
                    :label="t('documents.maxFileSizeMb')"
                    type="number"
                    :min="1"
                    :max="50"
                    suffix="Mo"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="form.accepted_types"
                    :label="t('documents.drawerAcceptedFormats')"
                    :items="acceptedTypeOptions"
                    multiple
                    chips
                    closable-chips
                  />
                </VCol>
              </template>

              <!-- Actions -->
              <VCol cols="12">
                <VBtn
                  type="submit"
                  class="me-4"
                  :loading="isLoading"
                >
                  {{ isEditMode ? t('common.save') : t('common.create') }}
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  @click="handleClose"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </div>
  </VNavigationDrawer>
</template>
