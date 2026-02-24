<script setup>
definePage({ meta: { surface: 'structure' } })

import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { useAuthStore } from '@/core/stores/auth'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const auth = useAuthStore()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()

const form = ref({
  name: '',
})

const dynamicForm = ref({})
const isLoading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

// Legal structure
const legalStatusKey = ref(null)
const legalLoading = ref(false)

const canEdit = computed(() => auth.hasPermission('settings.manage'))

const dynamicFields = computed(() => {
  return settingsStore.company?.dynamic_fields || []
})

const hasMarketAssigned = computed(() => {
  return !!settingsStore.marketInfo?.market_name
})

const availableLegalStatuses = computed(() => {
  return settingsStore.marketInfo?.legal_statuses || []
})

onMounted(async () => {
  await Promise.all([
    settingsStore.fetchCompany(),
    settingsStore.fetchLegalStructure(),
  ])

  const data = settingsStore.company
  form.value.name = data?.base_fields?.name || ''

  // Build dynamic form values from resolved fields
  const df = {}
  for (const field of data?.dynamic_fields || []) {
    df[field.code] = field.value
  }
  dynamicForm.value = df

  // Init legal status
  legalStatusKey.value = settingsStore.marketInfo?.legal_status_key || null
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

    successMessage.value = t('companySettings.settingsUpdated')
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('companySettings.failedToUpdate')
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

// ─── Legal Structure ─────────────────────────────────
const handleLegalSave = async () => {
  legalLoading.value = true

  try {
    const data = await settingsStore.updateLegalStatus(legalStatusKey.value)

    toast(data.message || t('companySettings.legalUpdated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    legalLoading.value = false
  }
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle>{{ t('companySettings.title') }}</VCardTitle>
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
                :label="t('companySettings.companyName')"
                :placeholder="t('companySettings.companyNamePlaceholder')"
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
                {{ t('companySettings.saveChanges') }}
              </VBtn>

              <VBtn
                color="secondary"
                variant="tonal"
                @click="resetForm"
              >
                {{ t('common.cancel') }}
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>

    <!-- Legal Structure (ADR-104) — only visible when market assigned by Platform -->
    <VCard
      v-if="hasMarketAssigned"
      class="mt-6"
    >
      <VCardTitle>{{ t('companySettings.legalStructureTitle') }}</VCardTitle>
      <VCardSubtitle>{{ settingsStore.marketInfo?.market_name }}</VCardSubtitle>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="legalStatusKey"
              :label="t('companySettings.legalStructure')"
              :placeholder="t('companySettings.legalStructurePlaceholder')"
              :items="availableLegalStatuses"
              item-title="name"
              item-value="key"
              :disabled="!canEdit"
              clearable
            >
              <template #item="{ props: itemProps, item }">
                <VListItem
                  v-bind="itemProps"
                  :subtitle="item.raw.description"
                />
              </template>
            </AppSelect>
          </VCol>

          <VCol
            v-if="canEdit"
            cols="12"
          >
            <VBtn
              :loading="legalLoading"
              @click="handleLegalSave"
            >
              {{ t('common.save') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
