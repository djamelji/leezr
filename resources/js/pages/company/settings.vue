<script setup>
definePage({ meta: { surface: 'structure' } })

import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { useAuthStore } from '@/core/stores/auth'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'

const auth = useAuthStore()
const settingsStore = useCompanySettingsStore()

const form = ref({
  name: '',
})

const dynamicForm = ref({})
const isLoading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const canEdit = computed(() => auth.hasPermission('settings.manage'))

const dynamicFields = computed(() => {
  return settingsStore.company?.dynamic_fields || []
})

onMounted(async () => {
  await settingsStore.fetchCompany()

  const data = settingsStore.company
  form.value.name = data?.base_fields?.name || ''

  // Build dynamic form values from resolved fields
  const df = {}
  for (const field of data?.dynamic_fields || []) {
    df[field.code] = field.value
  }
  dynamicForm.value = df
})

const handleSave = async () => {
  isLoading.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    const payload = { name: form.value.name }

    // Only include dynamic_fields if there are any
    if (dynamicFields.value.length > 0) {
      payload.dynamic_fields = { ...dynamicForm.value }
    }

    await settingsStore.updateCompany(payload)

    // Update the company name in auth store too
    await auth.fetchMyCompanies()

    successMessage.value = 'Company settings updated.'
  }
  catch (error) {
    errorMessage.value = error?.data?.message || 'Failed to update company settings.'
  }
  finally {
    isLoading.value = false
  }
}

const resetForm = () => {
  const data = settingsStore.company
  form.value.name = data?.base_fields?.name || ''

  const df = {}
  for (const field of data?.dynamic_fields || []) {
    df[field.code] = field.value
  }
  dynamicForm.value = df
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle>Company Settings</VCardTitle>
      <VCardText>
        <VAlert
          v-if="successMessage"
          type="success"
          class="mb-4"
          closable
          @click:close="successMessage = ''"
        >
          {{ successMessage }}
        </VAlert>

        <VAlert
          v-if="errorMessage"
          type="error"
          class="mb-4"
          closable
          @click:close="errorMessage = ''"
        >
          {{ errorMessage }}
        </VAlert>

        <VForm @submit.prevent="handleSave">
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.name"
                label="Company Name"
                placeholder="My Company"
                :disabled="!canEdit"
              />
            </VCol>

            <!-- Dynamic fields -->
            <DynamicFormRenderer
              v-if="dynamicFields.length"
              v-model="dynamicForm"
              :fields="dynamicFields"
              :disabled="!canEdit"
            />

            <VCol
              v-if="canEdit"
              cols="12"
              class="d-flex flex-wrap gap-4"
            >
              <VBtn
                type="submit"
                :loading="isLoading"
              >
                Save changes
              </VBtn>

              <VBtn
                color="secondary"
                variant="tonal"
                @click="resetForm"
              >
                Cancel
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
