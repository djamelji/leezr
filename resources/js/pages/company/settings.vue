<script setup>
definePage({ meta: { surface: 'structure' } })

import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { useAuthStore } from '@/core/stores/auth'
import { useWorldStore } from '@/core/stores/world'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const auth = useAuthStore()
const worldStore = useWorldStore()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()

const form = ref({
  name: '',
})

const dynamicForm = ref({})
const isLoading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

// Market form
const marketForm = ref({
  market_key: null,
  legal_status_key: null,
})

const marketLoading = ref(false)

const canEdit = computed(() => auth.hasPermission('settings.manage'))

const dynamicFields = computed(() => {
  return settingsStore.company?.dynamic_fields || []
})

const availableMarkets = computed(() => {
  return settingsStore.marketInfo?.markets || []
})

const availableLegalStatuses = computed(() => {
  return settingsStore.marketInfo?.legal_statuses || []
})

onMounted(async () => {
  await Promise.all([
    settingsStore.fetchCompany(),
    settingsStore.fetchMarketInfo(),
  ])

  const data = settingsStore.company
  form.value.name = data?.base_fields?.name || ''

  // Build dynamic form values from resolved fields
  const df = {}
  for (const field of data?.dynamic_fields || []) {
    df[field.code] = field.value
  }
  dynamicForm.value = df

  // Init market form
  const mi = settingsStore.marketInfo
  marketForm.value.market_key = mi?.market_key || null
  marketForm.value.legal_status_key = mi?.legal_status_key || null
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

// ─── Market / Legal Status ────────────────────────────
const handleMarketSave = async () => {
  marketLoading.value = true

  try {
    const data = await settingsStore.updateMarket({ ...marketForm.value })

    // Update world store with new market data
    const selectedMarket = availableMarkets.value.find(m => m.key === marketForm.value.market_key)
    if (selectedMarket)
      worldStore.applyMarket(selectedMarket)

    // Sync market form with refreshed data
    const mi = settingsStore.marketInfo
    marketForm.value.market_key = mi?.market_key || null
    marketForm.value.legal_status_key = mi?.legal_status_key || null

    toast(data.message || t('companySettings.marketUpdated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    marketLoading.value = false
  }
}

// When market changes, clear legal status and load legal statuses for new market
watch(() => marketForm.value.market_key, async (newKey, oldKey) => {
  if (newKey !== oldKey && oldKey !== undefined) {
    marketForm.value.legal_status_key = null

    // Fetch legal statuses for newly selected market via public API
    if (newKey) {
      try {
        const res = await fetch(`/api/public/markets/${newKey}`)
        if (res.ok) {
          const data = await res.json()

          settingsStore.$patch(state => {
            state._marketInfo = {
              ...state._marketInfo,
              legal_statuses: data.legal_statuses || [],
            }
          })
        }
      }
      catch {
        // Ignore — legal statuses will be empty
      }
    }
    else {
      settingsStore.$patch(state => {
        state._marketInfo = { ...state._marketInfo, legal_statuses: [] }
      })
    }
  }
})
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

    <!-- Market & Legal Status (ADR-104) -->
    <VCard class="mt-6">
      <VCardTitle>{{ t('companySettings.marketTitle') }}</VCardTitle>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="marketForm.market_key"
              :label="t('companySettings.market')"
              :items="availableMarkets"
              item-title="name"
              item-value="key"
              :disabled="!canEdit"
              clearable
            >
              <template #item="{ props: itemProps, item }">
                <VListItem
                  v-bind="itemProps"
                  :subtitle="`${item.raw.currency} · ${item.raw.locale}`"
                />
              </template>
            </AppSelect>
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="marketForm.legal_status_key"
              :label="t('companySettings.legalStatus')"
              :items="availableLegalStatuses"
              item-title="name"
              item-value="key"
              :disabled="!canEdit || !marketForm.market_key"
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
              :loading="marketLoading"
              @click="handleMarketSave"
            >
              {{ t('companySettings.saveMarket') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
