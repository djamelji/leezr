<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const policiesLoading = ref(false)

const policiesForm = ref({
  payment_required: false,
  admin_approval_required: true,
  annual_only: false,
  currency: 'usd',
  vat_enabled: false,
  vat_rate: 0,
})

onMounted(async () => {
  try {
    await store.fetchPolicies()
    policiesForm.value = { ...store.policies }
  }
  finally {
    isLoading.value = false
  }
})

const currencyOptions = [
  { title: 'USD ($)', value: 'usd' },
  { title: 'EUR (\u20AC)', value: 'eur' },
  { title: 'GBP (\u00A3)', value: 'gbp' },
]

const savePolicies = async () => {
  policiesLoading.value = true

  try {
    const data = await store.updatePolicies(policiesForm.value)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToUpdatePolicies'), 'error')
  }
  finally {
    policiesLoading.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardTitle>
      <VIcon
        icon="tabler-shield-check"
        class="me-2"
      />
      {{ t('payments.paymentPolicies') }}
    </VCardTitle>

    <VCardText>
      <VSkeletonLoader
        v-if="isLoading"
        type="card"
      />

      <VRow v-else>
        <VCol
          cols="12"
          md="6"
        >
          <VSwitch
            v-model="policiesForm.payment_required"
            :label="t('payments.paymentRequired')"
            class="mb-4"
          />
        </VCol>

        <VCol
          cols="12"
          md="6"
        >
          <VSwitch
            v-model="policiesForm.admin_approval_required"
            :label="t('payments.adminApproval')"
            class="mb-4"
          />
        </VCol>

        <VCol
          cols="12"
          md="6"
        >
          <VSwitch
            v-model="policiesForm.annual_only"
            :label="t('payments.annualOnly')"
            class="mb-4"
          />
        </VCol>

        <VCol
          cols="12"
          md="6"
        >
          <VSelect
            v-model="policiesForm.currency"
            :items="currencyOptions"
            :label="t('payments.primaryCurrency')"
          />
        </VCol>

        <VCol
          cols="12"
          md="6"
        >
          <VSwitch
            v-model="policiesForm.vat_enabled"
            :label="t('payments.vatApplicable')"
            class="mb-4"
          />
        </VCol>

        <VCol
          v-if="policiesForm.vat_enabled"
          cols="12"
          md="6"
        >
          <AppTextField
            v-model.number="policiesForm.vat_rate"
            :label="t('payments.vatRate')"
            type="number"
            min="0"
            max="100"
            suffix="%"
          />
        </VCol>

        <VCol cols="12">
          <VBtn
            :loading="policiesLoading"
            @click="savePolicies"
          >
            {{ t('payments.savePolicies') }}
          </VBtn>
        </VCol>
      </VRow>
    </VCardText>
  </VCard>
</template>
