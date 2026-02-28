<script setup>
import { useBillingPolicyStore } from '@/modules/platform-admin/billing/billingPolicy.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = useBillingPolicyStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)
const loadError = ref(false)

const defaults = {
  wallet_first: true,
  allow_negative_wallet: false,
  auto_apply_wallet_credit: true,
  grace_period_days: 3,
  max_retry_attempts: 3,
  retry_intervals_days: [1, 3, 7],
  failure_action: 'suspend',
}

const safeKeys = Object.keys(defaults)

const form = reactive({ ...defaults })

let snapshot = JSON.stringify(defaults)

const loadSettings = data => {
  for (const key of safeKeys) {
    if (key in data) {
      form[key] = key === 'retry_intervals_days'
        ? [...data[key]]
        : data[key]
    }
  }
  snapshot = JSON.stringify(form)
}

onMounted(async () => {
  try {
    await store.fetchPolicy()
    if (store.policy)
      loadSettings(store.policy)
  }
  catch (error) {
    if (error?.status === 403)
      loadError.value = true
  }
  finally {
    isLoading.value = false
  }
})

const isDirty = computed(() => JSON.stringify(form) !== snapshot)

// Retry intervals auto-resize
watch(() => form.max_retry_attempts, (newVal, oldVal) => {
  if (newVal === oldVal) return
  const arr = [...form.retry_intervals_days]
  if (newVal > oldVal) {
    while (arr.length < newVal) {
      const last = arr.length > 0 ? arr[arr.length - 1] : 0
      arr.push(last + 7)
    }
  }
  else {
    arr.length = Math.max(0, newVal)
  }
  form.retry_intervals_days = arr
})

const retryIntervalsError = computed(() => {
  const intervals = form.retry_intervals_days
  if (intervals.length !== form.max_retry_attempts) {
    return t('platformSettings.billing.retryIntervalLengthError', {
      expected: form.max_retry_attempts,
      got: intervals.length,
    })
  }
  for (let i = 1; i < intervals.length; i++) {
    if (intervals[i] <= intervals[i - 1])
      return t('platformSettings.billing.retryIntervalIncreasingError')
  }

  return null
})

const hasErrors = computed(() => !!retryIntervalsError.value)

const failureActionOptions = computed(() => [
  { title: t('platformSettings.billing.suspend'), value: 'suspend' },
  { title: t('platformSettings.billing.downgradeToStarter'), value: 'downgrade_to_starter' },
  { title: t('platformSettings.billing.readOnly'), value: 'read_only' },
])

const save = async () => {
  if (hasErrors.value) return
  isSaving.value = true
  try {
    const data = await store.updatePolicy({
      ...form,
      retry_intervals_days: [...form.retry_intervals_days],
    })

    toast(t('billingSettings.saved'), 'success')
    loadSettings(data.policy)
  }
  catch (error) {
    toast(error?.data?.message || t('platformSettings.billing.failedToSave'), 'error')
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div>
    <VCard :loading="isLoading">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-shield-check"
          class="me-2"
        />
        {{ t('billingSettings.policies.title') }}
      </VCardTitle>

      <VCardText v-if="loadError">
        <VAlert
          type="warning"
          variant="tonal"
        >
          {{ t('platformSettings.billing.noPermission') }}
        </VAlert>
      </VCardText>

      <VCardText v-else-if="!isLoading">
        <!-- Wallet Behavior -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.billing.walletSection') }}
        </h6>

        <div class="d-flex flex-column gap-4 mb-4">
          <div class="d-flex align-center justify-space-between">
            <VLabel for="wallet-first">
              {{ t('billingSettings.policies.walletFirst') }}
            </VLabel>
            <VSwitch
              id="wallet-first"
              v-model="form.wallet_first"
              hide-details
            />
          </div>

          <div class="d-flex align-center justify-space-between">
            <VLabel for="allow-negative">
              {{ t('billingSettings.policies.allowNegativeWallet') }}
            </VLabel>
            <VSwitch
              id="allow-negative"
              v-model="form.allow_negative_wallet"
              hide-details
            />
          </div>

          <div class="d-flex align-center justify-space-between">
            <div>
              <VLabel for="auto-apply">
                {{ t('billingSettings.policies.autoApplyWalletCredit') }}
              </VLabel>
              <p class="text-caption text-medium-emphasis mt-1">
                {{ t('platformSettings.billing.autoApplyWalletCreditHint') }}
              </p>
            </div>
            <VSwitch
              id="auto-apply"
              v-model="form.auto_apply_wallet_credit"
              hide-details
            />
          </div>
        </div>

        <VDivider class="mb-6" />

        <!-- Dunning Policy -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.billing.dunningSection') }}
        </h6>

        <VAlert
          type="warning"
          variant="tonal"
          class="mb-4"
        >
          {{ t('platformSettings.billing.dunningWarning') }}
        </VAlert>

        <VRow class="mb-2">
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model.number="form.grace_period_days"
              :label="t('billingSettings.policies.gracePeriodDays')"
              type="number"
              min="0"
              max="365"
              :hint="t('platformSettings.billing.gracePeriodDaysHint')"
              persistent-hint
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model.number="form.max_retry_attempts"
              :label="t('billingSettings.policies.maxRetryAttempts')"
              type="number"
              min="0"
              max="20"
              :hint="t('platformSettings.billing.maxRetryAttemptsHint')"
              persistent-hint
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="form.failure_action"
              :label="t('billingSettings.policies.failureAction')"
              :items="failureActionOptions"
              :hint="t('platformSettings.billing.failureActionHint')"
              persistent-hint
            />
          </VCol>
        </VRow>

        <VRow
          v-if="form.max_retry_attempts > 0"
          class="mb-2"
        >
          <VCol
            v-for="(_, idx) in form.retry_intervals_days"
            :key="idx"
            cols="12"
            md="3"
          >
            <AppTextField
              v-model.number="form.retry_intervals_days[idx]"
              :label="t('billingSettings.policies.retryInterval', { n: idx + 1 })"
              type="number"
              min="1"
              max="90"
            />
          </VCol>
        </VRow>

        <VAlert
          v-if="retryIntervalsError"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ retryIntervalsError }}
        </VAlert>
      </VCardText>

      <VDivider />

      <VCardActions class="pa-4">
        <VBtn
          color="primary"
          :loading="isSaving"
          :disabled="isLoading || hasErrors || !isDirty || loadError"
          @click="save"
        >
          {{ t('common.save') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
