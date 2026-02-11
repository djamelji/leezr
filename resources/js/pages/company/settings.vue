<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyStore } from '@/core/stores/company'

const auth = useAuthStore()
const companyStore = useCompanyStore()

const form = ref({
  name: '',
})

const isLoading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const canEdit = computed(() => {
  const role = auth.currentCompany?.role
  return role === 'owner' || role === 'admin'
})

onMounted(async () => {
  await companyStore.fetchCompany()
  form.value.name = companyStore.company?.name || ''
})

const handleSave = async () => {
  isLoading.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    await companyStore.updateCompany({ name: form.value.name })

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
  form.value.name = companyStore.company?.name || ''
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
