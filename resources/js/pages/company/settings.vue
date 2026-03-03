<script setup>
definePage({ meta: { module: 'core.settings', surface: 'structure' } })

import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import CompanyDocumentsSection from '@/views/pages/company-settings/CompanyDocumentsSection.vue'
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

// ADR-169 Phase 4: Storage quota
const storageInfo = computed(() => settingsStore.company?.storage || null)

// ADR-174: Company documents vault
const companyDocuments = computed(() => settingsStore.companyDocuments)
const hasCompanyDocuments = computed(() => companyDocuments.value.length > 0)

// ADR-175: Document activation catalog
const documentActivations = computed(() => settingsStore.documentActivations)

const hasMarketAssigned = computed(() => {
  return !!settingsStore.marketInfo?.market_name
})

// ADR-168b: jobdomain mandatory fields
const jobdomainMandatoryFields = computed(() => {
  return settingsStore.company?.jobdomain_mandatory_fields || []
})

const availableLegalStatuses = computed(() => {
  return settingsStore.marketInfo?.legal_statuses || []
})

onMounted(async () => {
  await Promise.all([
    settingsStore.fetchCompany(),
    settingsStore.fetchLegalStructure(),
    settingsStore.fetchCompanyDocuments(),
    settingsStore.fetchDocumentActivations(),
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

    <!-- ADR-168b: jobdomain mandatory fields -->
    <VCard
      v-if="jobdomainMandatoryFields.length"
      class="mt-6"
    >
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="warning"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-clipboard-check" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companySettings.mandatoryByJobdomain') }}</VCardTitle>
        <VCardSubtitle>{{ t('companySettings.mandatoryByJobdomainHint') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VAlert
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('companySettings.mandatoryByJobdomainExplainer') }}
        </VAlert>
        <VList density="compact">
          <VListItem
            v-for="field in jobdomainMandatoryFields"
            :key="field.code"
          >
            <template #prepend>
              <VIcon
                icon="tabler-alert-circle"
                color="warning"
                size="20"
              />
            </template>
            <VListItemTitle>{{ field.label }}</VListItemTitle>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <!-- ADR-169 Phase 4: Storage Usage -->
    <VCard
      v-if="storageInfo"
      class="mt-6"
    >
      <VCardItem>
        <template #prepend>
          <VAvatar
            :color="storageInfo.warning ? 'error' : 'primary'"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-database" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companySettings.storageTitle') }}</VCardTitle>
        <VCardSubtitle>{{ storageInfo.used_display }} / {{ storageInfo.limit_display }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VAlert
          v-if="storageInfo.blocked"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('companySettings.storageBlocked') }}
        </VAlert>
        <VAlert
          v-else-if="storageInfo.warning"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('companySettings.storageWarning') }}
        </VAlert>

        <VProgressLinear
          :model-value="storageInfo.percentage"
          :color="storageInfo.blocked ? 'error' : storageInfo.warning ? 'warning' : 'primary'"
          height="8"
          rounded
        />
        <div class="d-flex justify-space-between mt-2">
          <span class="text-body-2 text-medium-emphasis">{{ storageInfo.percentage }}%</span>
          <span class="text-body-2 text-medium-emphasis">{{ storageInfo.limit_display }}</span>
        </div>
      </VCardText>
    </VCard>

    <!-- ADR-178: Unified Documents Section -->
    <CompanyDocumentsSection
      :document-activations="documentActivations"
      :company-documents="companyDocuments"
      :can-edit="canEdit"
      @refresh-activations="settingsStore.fetchDocumentActivations()"
      @refresh-documents="settingsStore.fetchCompanyDocuments()"
    />

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
