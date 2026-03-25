<script setup>
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  isDrawerOpen: {
    type: Boolean,
    required: true,
  },
})

const emit = defineEmits(['update:isDrawerOpen', 'created'])

const { t } = useI18n()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()

const isLoading = ref(false)

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
})

const form = ref(defaultForm())

const handleClose = () => {
  emit('update:isDrawerOpen', false)
  form.value = defaultForm()
  isLoading.value = false
}

const handleSubmit = async () => {
  isLoading.value = true

  try {
    await settingsStore.createCustomDocumentType({ ...form.value })
    toast(t('documents.customTypeCreated'), 'success')
    emit('created')
    handleClose()
    settingsStore.fetchDocumentActivations()
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
      :title="t('documents.createCustomDocument')"
      @cancel="handleClose"
    />

    <VDivider />

    <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
      <VCard flat>
        <VCardText>
          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('documents.drawerCreateHint') }}
          </VAlert>

          <VForm @submit.prevent="handleSubmit">
            <VRow>
              <VCol cols="12">
                <AppTextField
                  v-model="form.label"
                  :label="t('documents.documentLabel')"
                  :placeholder="t('documents.documentLabel')"
                />
              </VCol>
              <VCol cols="12">
                <AppSelect
                  v-model="form.scope"
                  :label="t('documents.scope')"
                  :items="scopeOptions"
                  :hint="form.scope === 'company_user' ? t('documents.scopeHintMember') : t('documents.scopeHintCompany')"
                  persistent-hint
                />
              </VCol>

              <VCol cols="12">
                <h6 class="text-overline text-medium-emphasis mb-2">
                  {{ t('documents.fileConstraints') }}
                </h6>
              </VCol>

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
                  :hint="t('documents.maxFileSizeHint')"
                  persistent-hint
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <VSelect
                  v-model="form.accepted_types"
                  :label="t('documents.acceptedTypes')"
                  :items="acceptedTypeOptions"
                  multiple
                  chips
                  closable-chips
                />
              </VCol>

              <VCol cols="12">
                <h6 class="text-overline text-medium-emphasis mb-2">
                  {{ t('documents.options') }}
                </h6>
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model.number="form.order"
                  :label="t('companySettings.order')"
                  type="number"
                  :min="0"
                  :hint="t('documents.orderHint')"
                  persistent-hint
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <VCheckbox
                  v-model="form.required"
                  :label="t('companySettings.requiredOverride')"
                  :hint="t('documents.requiredHint')"
                  persistent-hint
                />
              </VCol>
              <VCol cols="12">
                <VBtn
                  type="submit"
                  class="me-4"
                  :loading="isLoading"
                >
                  {{ t('common.create') }}
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
