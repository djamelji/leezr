<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { useAuthStore } from '@/core/stores/auth'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const auth = useAuthStore()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()

const form = ref({ name: '' })
const dynamicForm = ref({})
const isLoading = ref(false)
const legalStatusKey = ref(null)
const legalLoading = ref(false)

const canEdit = computed(() => auth.hasPermission('settings.manage'))

const dynamicFields = computed(() => settingsStore.company?.dynamic_fields || [])

const jobdomainMandatoryFields = computed(() =>
  settingsStore.company?.jobdomain_mandatory_fields || [],
)

const hasMarketAssigned = computed(() => !!settingsStore.marketInfo?.market_name)

const availableLegalStatuses = computed(() =>
  settingsStore.marketInfo?.legal_statuses || [],
)

const initForm = () => {
  const data = settingsStore.company

  form.value.name = data?.base_fields?.name || ''

  const df = {}
  for (const field of data?.dynamic_fields || []) {
    df[field.code] = field.value
  }
  dynamicForm.value = df

  legalStatusKey.value = settingsStore.marketInfo?.legal_status_key || null
}

onMounted(initForm)

const handleSave = async () => {
  isLoading.value = true

  try {
    const payload = { name: form.value.name }

    if (dynamicFields.value.length > 0) {
      payload.dynamic_fields = { ...dynamicForm.value }
    }

    await settingsStore.updateCompany(payload)
    await auth.fetchMyCompanies()

    toast(t('companySettings.settingsUpdated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('companySettings.failedToUpdate'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

const resetForm = () => initForm()

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
