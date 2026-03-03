<script setup>
/**
 * ADR-180: Dialog for creating a custom document type.
 * Pattern: Vuexy AddEditRoleDialog preset.
 */
const props = defineProps({
  isDialogVisible: {
    type: Boolean,
    required: true,
  },
})

const emit = defineEmits([
  'update:isDialogVisible',
  'created',
])

const { t } = useI18n()

const form = ref({
  label: '',
  scope: 'company_user',
  max_file_size_mb: 10,
  accepted_types: ['pdf', 'jpg', 'jpeg', 'png'],
  order: 0,
  required: false,
})

const loading = ref(false)

const scopeOptions = [
  { title: 'Member', value: 'company_user' },
  { title: 'Company', value: 'company' },
]

const acceptedTypeOptions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']

const onClose = () => {
  emit('update:isDialogVisible', false)
}

const onSubmit = () => {
  loading.value = true
  emit('created', { ...form.value })
}

// Reset form when dialog closes
watch(() => props.isDialogVisible, val => {
  if (!val) {
    form.value = {
      label: '',
      scope: 'company_user',
      max_file_size_mb: 10,
      accepted_types: ['pdf', 'jpg', 'jpeg', 'png'],
      order: 0,
      required: false,
    }
    loading.value = false
  }
})
</script>

<template>
  <VDialog
    :width="$vuetify.display.smAndDown ? 'auto' : 600"
    :model-value="props.isDialogVisible"
    @update:model-value="onClose"
  >
    <DialogCloseBtn @click="onClose" />

    <VCard class="pa-sm-10 pa-2">
      <VCardText>
        <h4 class="text-h4 text-center mb-2">
          {{ t('documents.createCustomDocument') }}
        </h4>

        <VForm @submit.prevent="onSubmit">
          <VRow class="mt-4">
            <VCol cols="12">
              <AppTextField
                v-model="form.label"
                :label="t('documents.documentLabel')"
                :rules="[v => !!v || t('documents.documentLabel')]"
                autofocus
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="form.scope"
                :label="t('documents.scope')"
                :items="scopeOptions"
              />
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
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="form.accepted_types"
                :label="t('documents.acceptedTypes')"
                :items="acceptedTypeOptions"
                multiple
                chips
                closable-chips
              />
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
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VCheckbox
                v-model="form.required"
                :label="t('companySettings.requiredOverride')"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex justify-center gap-4">
                <VBtn
                  type="submit"
                  :loading="loading"
                >
                  {{ t('common.create') }}
                </VBtn>
                <VBtn
                  color="secondary"
                  variant="tonal"
                  @click="onClose"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </VDialog>
</template>
