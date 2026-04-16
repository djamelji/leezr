<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { useAuthStore } from '@/core/stores/auth'
import { useWorldStore } from '@/core/stores/world'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const auth = useAuthStore()
const world = useWorldStore()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()

const form = ref({ name: '' })
const dynamicForm = ref({})
const isLoading = ref(false)
const billingIsSameAsCompany = ref(false)

const canEdit = computed(() => auth.hasPermission('settings.manage'))

const dynamicFields = computed(() => settingsStore.company?.dynamic_fields || [])

const jobdomainMandatoryFields = computed(() =>
  settingsStore.company?.jobdomain_mandatory_fields || [],
)


// ─── Field helpers ──────────────────────────────────
const fieldByCode = code => dynamicFields.value.find(f => f.code === code)
const hasField = code => !!fieldByCode(code)
const fieldLabel = code => fieldByCode(code)?.label || code

const phonePlaceholder = computed(() => {
  const dc = world.dialCode || '+33'

  return `${dc} 6 12 34 56 78`
})

// ─── Structured field groups ────────────────────────
const companyAddressCodes = ['company_address', 'company_complement', 'company_city', 'company_postal_code', 'company_region']
const billingAddressCodes = ['billing_address', 'billing_complement', 'billing_city', 'billing_postal_code', 'billing_region', 'billing_email']
const contactCodes = ['company_phone']
const knownFieldCodes = [...companyAddressCodes, ...billingAddressCodes, ...contactCodes]

const companyAddressFields = computed(() => companyAddressCodes.filter(hasField))
const billingAddressFields = computed(() => billingAddressCodes.filter(hasField))
const contactFields = computed(() => contactCodes.filter(hasField))

const remainingFields = computed(() =>
  dynamicFields.value.filter(f => !knownFieldCodes.includes(f.code)),
)

// ─── Billing = Company address toggle ───────────────
const addressMap = {
  company_address: 'billing_address',
  company_complement: 'billing_complement',
  company_city: 'billing_city',
  company_postal_code: 'billing_postal_code',
  company_region: 'billing_region',
}

const copyCompanyToBilling = () => {
  for (const [src, dst] of Object.entries(addressMap)) {
    if (hasField(dst))
      dynamicForm.value[dst] = dynamicForm.value[src] || ''
  }
}

watch(billingIsSameAsCompany, val => {
  if (val)
    copyCompanyToBilling()
})

// ─── Form init / save ───────────────────────────────
const initForm = () => {
  const data = settingsStore.company

  form.value.name = data?.base_fields?.name || ''

  const df = {}
  for (const field of data?.dynamic_fields || []) {
    df[field.code] = field.value
  }
  dynamicForm.value = df

  billingIsSameAsCompany.value = false
}

watch(() => settingsStore.company, () => initForm(), { immediate: true })

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

</script>

<template>
  <VForm @submit.prevent="handleSave">
    <!-- ─── General Info ────────────────────────────── -->
    <VCard>
      <VCardText>
        <h5 class="text-h5 mb-6">
          {{ t('companySettings.title') }}
        </h5>
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

          <VCol
            v-if="hasField('company_phone')"
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="dynamicForm.company_phone"
              :label="fieldLabel('company_phone')"
              :placeholder="phonePlaceholder"
              :disabled="!canEdit"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ─── Company Address ─────────────────────────── -->
    <VCard
      v-if="companyAddressFields.length"
      class="mt-6"
    >
      <VCardText>
        <h5 class="text-h5 mb-6">
          {{ t('companySettings.companyAddress') }}
        </h5>
        <VRow>
          <VCol
            v-if="hasField('company_address')"
            cols="12"
          >
            <AppTextField
              v-model="dynamicForm.company_address"
              :label="fieldLabel('company_address')"
              :placeholder="t('companySettings.placeholder.street')"
              :disabled="!canEdit"
            />
          </VCol>
          <VCol
            v-if="hasField('company_complement')"
            cols="12"
          >
            <AppTextField
              v-model="dynamicForm.company_complement"
              :label="fieldLabel('company_complement')"
              :placeholder="t('companySettings.placeholder.complement')"
              :disabled="!canEdit"
            />
          </VCol>
          <VCol
            v-if="hasField('company_city')"
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="dynamicForm.company_city"
              :label="fieldLabel('company_city')"
              :placeholder="t('companySettings.placeholder.city')"
              :disabled="!canEdit"
            />
          </VCol>
          <VCol
            v-if="hasField('company_postal_code')"
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="dynamicForm.company_postal_code"
              :label="fieldLabel('company_postal_code')"
              :placeholder="t('companySettings.placeholder.postalCode')"
              :disabled="!canEdit"
            />
          </VCol>
          <VCol
            v-if="hasField('company_region')"
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="dynamicForm.company_region"
              :label="fieldLabel('company_region')"
              :placeholder="t('companySettings.placeholder.region')"
              :disabled="!canEdit"
            />
          </VCol>
          <VCol
            v-if="settingsStore.marketInfo?.market_name"
            cols="12"
            md="6"
          >
            <AppTextField
              :model-value="settingsStore.marketInfo.market_name"
              :label="t('companySettings.country')"
              disabled
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ─── Billing Address ─────────────────────────── -->
    <VCard
      v-if="billingAddressFields.length"
      class="mt-6"
    >
      <VCardText>
        <div class="d-flex justify-space-between align-center mb-6">
          <h5 class="text-h5">
            {{ t('companySettings.billingAddress') }}
          </h5>
        </div>

        <VSwitch
          v-if="companyAddressFields.length"
          v-model="billingIsSameAsCompany"
          :label="t('companySettings.sameAsCompanyAddress')"
          :disabled="!canEdit"
          class="mb-6"
        />

        <VRow>
          <VCol
            v-if="hasField('billing_address')"
            cols="12"
          >
            <AppTextField
              v-model="dynamicForm.billing_address"
              :label="fieldLabel('billing_address')"
              :placeholder="t('companySettings.placeholder.street')"
              :disabled="!canEdit || billingIsSameAsCompany"
            />
          </VCol>
          <VCol
            v-if="hasField('billing_complement')"
            cols="12"
          >
            <AppTextField
              v-model="dynamicForm.billing_complement"
              :label="fieldLabel('billing_complement')"
              :placeholder="t('companySettings.placeholder.complement')"
              :disabled="!canEdit || billingIsSameAsCompany"
            />
          </VCol>
          <VCol
            v-if="hasField('billing_city')"
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="dynamicForm.billing_city"
              :label="fieldLabel('billing_city')"
              :placeholder="t('companySettings.placeholder.city')"
              :disabled="!canEdit || billingIsSameAsCompany"
            />
          </VCol>
          <VCol
            v-if="hasField('billing_postal_code')"
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="dynamicForm.billing_postal_code"
              :label="fieldLabel('billing_postal_code')"
              :placeholder="t('companySettings.placeholder.postalCode')"
              :disabled="!canEdit || billingIsSameAsCompany"
            />
          </VCol>
          <VCol
            v-if="hasField('billing_region')"
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="dynamicForm.billing_region"
              :label="fieldLabel('billing_region')"
              :placeholder="t('companySettings.placeholder.region')"
              :disabled="!canEdit || billingIsSameAsCompany"
            />
          </VCol>
          <VCol
            v-if="hasField('billing_email')"
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="dynamicForm.billing_email"
              :label="fieldLabel('billing_email')"
              :placeholder="t('companySettings.placeholder.email')"
              :disabled="!canEdit"
            />
          </VCol>
          <VCol
            v-if="settingsStore.marketInfo?.market_name"
            cols="12"
            md="6"
          >
            <AppTextField
              :model-value="settingsStore.marketInfo.market_name"
              :label="t('companySettings.country')"
              disabled
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ─── Other Fields (fiscal, etc.) ─────────────── -->
    <VCard
      v-if="remainingFields.length"
      class="mt-6"
    >
      <VCardText>
        <VRow>
          <DynamicFormRenderer
            v-model="dynamicForm"
            :fields="remainingFields"
            :disabled="!canEdit"
          />
        </VRow>
      </VCardText>
    </VCard>

    <!-- ─── Save / Cancel ───────────────────────────── -->
    <div
      v-if="canEdit"
      class="d-flex flex-wrap gap-4 mt-6"
    >
      <VBtn
        v-can="'settings.manage'"
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
    </div>
  </VForm>

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

</template>
