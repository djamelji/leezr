<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'

const { t } = useI18n()

const props = defineProps({
  companyFields: { type: Array, required: true },
  markets: { type: Array, required: true },
  legalStatuses: { type: Array, required: true },
  marketsLoading: { type: Boolean, default: false },
  legalStatusesLoading: { type: Boolean, default: false },
  fieldsLoading: { type: Boolean, default: false },
  marketDialCode: { type: String, default: '+33' },
  errors: { type: Object, default: () => ({}) },
})

const companyName = defineModel('companyName', { type: String })
const selectedMarket = defineModel('selectedMarket', { type: String })
const legalStatusKey = defineModel('legalStatusKey', { type: String })
const dynamicFieldValues = defineModel('dynamicFieldValues', { type: Object })
const billingIsSameAsCompany = defineModel('billingIsSameAsCompany', { type: Boolean })

const generalFields = computed(() =>
  props.companyFields.filter(f => f.group === 'general').map(({ group, ...rest }) => rest),
)

const addressFields = computed(() =>
  props.companyFields.filter(f => ['address', 'contact'].includes(f.group)).map(({ group, ...rest }) => rest),
)

const billingFields = computed(() =>
  props.companyFields.filter(f => f.group === 'billing' && f.code !== 'billing_email').map(({ group, ...rest }) => rest),
)

const billingEmailField = computed(() =>
  props.companyFields.filter(f => f.code === 'billing_email').map(({ group, ...rest }) => rest),
)

const legalStatusItems = computed(() => {
  return props.legalStatuses.map(ls => ({
    title: ls.description ? `${ls.name} — ${ls.description}` : ls.name,
    value: ls.key,
  }))
})
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('register.companyDetails') }}
  </h4>
  <p class="text-body-1 mb-6">
    {{ t('register.companyDetailsDesc') }}
  </p>

  <!-- Company name + Country + Legal status -->
  <VRow>
    <VCol
      cols="12"
      md="6"
    >
      <AppTextField
        v-model="companyName"
        :label="t('register.companyName')"
        :placeholder="t('register.companyNamePlaceholder')"
        :error-messages="errors.company_name"
      />
    </VCol>
    <VCol
      cols="12"
      md="6"
    >
      <AppSelect
        v-model="selectedMarket"
        :label="t('register.country')"
        :items="markets.map(mk => ({ title: mk.name, value: mk.key }))"
        :placeholder="t('register.selectCountry')"
        :loading="marketsLoading"
      />
    </VCol>
    <VCol
      cols="12"
      md="6"
    >
      <AppSelect
        v-model="legalStatusKey"
        :label="t('register.legalStatus')"
        :items="legalStatusItems"
        :placeholder="t('register.selectLegalStatus')"
        :disabled="!selectedMarket"
        :loading="legalStatusesLoading"
        clearable
      />
    </VCol>
  </VRow>

  <!-- General fields (siret, vat_number, legal_name) -->
  <VSkeletonLoader
    v-if="fieldsLoading"
    type="text, text"
    class="mt-2"
  />
  <template v-else>
    <VRow v-if="generalFields.length > 0">
      <DynamicFormRenderer
        v-model="dynamicFieldValues"
        :fields="generalFields"
        :cols="6"
        :dial-code="marketDialCode"
      />
    </VRow>

    <!-- Company address + contact -->
    <template v-if="addressFields.length > 0">
      <VDivider class="my-4" />
      <h6 class="text-h6 mb-2">
        {{ t('register.companyAddress') }}
      </h6>
      <VRow>
        <DynamicFormRenderer
          v-model="dynamicFieldValues"
          :fields="addressFields"
          :cols="6"
          :dial-code="marketDialCode"
        />
      </VRow>
    </template>

    <!-- Billing address toggle -->
    <VDivider class="my-4" />
    <h6 class="text-h6 mb-2">
      {{ t('register.billingAddress') }}
    </h6>
    <VSwitch
      v-model="billingIsSameAsCompany"
      :label="t('register.billingSameAsCompany')"
      class="mb-2"
    />

    <!-- Billing address fields (conditional) -->
    <VRow v-if="!billingIsSameAsCompany && billingFields.length > 0">
      <DynamicFormRenderer
        v-model="dynamicFieldValues"
        :fields="billingFields"
        :cols="6"
        :dial-code="marketDialCode"
      />
    </VRow>

    <!-- Billing email (always visible) -->
    <VRow v-if="billingEmailField.length > 0">
      <DynamicFormRenderer
        v-model="dynamicFieldValues"
        :fields="billingEmailField"
        :cols="6"
        :dial-code="marketDialCode"
      />
    </VRow>
  </template>
</template>
