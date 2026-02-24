<script setup>
definePage({ meta: { surface: 'structure', module: 'core.billing' } })

import { useAuthStore } from '@/core/stores/auth'
import { usePublicPlans } from '@/composables/usePublicPlans'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const auth = useAuthStore()
const { toast } = useAppToast()
const { plans, loading, fetchPlans } = usePublicPlans()

const annualToggle = ref(true)
const changingPlan = ref(false)
const isConfirmDialogVisible = ref(false)
const pendingPlanKey = ref(null)
const hasPendingSubscription = ref(false)
const checkingPending = ref(true)

const currentPlanKey = computed(() => auth.currentCompany?.plan_key ?? 'starter')
const currentPlan = computed(() => plans.value.find(p => p.key === currentPlanKey.value))

onMounted(async () => {
  await fetchPlans()
  checkingPending.value = false
})

const planColor = key => {
  const colors = { starter: 'secondary', pro: 'primary', business: 'warning' }

  return colors[key] || 'primary'
}

const requestPlanChange = planKey => {
  if (planKey === currentPlanKey.value) return
  if (hasPendingSubscription.value) return
  pendingPlanKey.value = planKey
  isConfirmDialogVisible.value = true
}

const confirmPlanChange = async isConfirmed => {
  if (!isConfirmed || !pendingPlanKey.value) {
    pendingPlanKey.value = null

    return
  }

  changingPlan.value = true

  try {
    const result = await $api('/billing/checkout', {
      method: 'POST',
      body: { plan_key: pendingPlanKey.value },
    })

    if (result.mode === 'internal') {
      hasPendingSubscription.value = true
      toast(result.message, 'info')
    }
    else if (result.mode === 'redirect' && result.redirect_url) {
      window.location.href = result.redirect_url
    }
  }
  catch {
    toast(t('companyPlan.failedToSubmit'), 'error')
  }
  finally {
    changingPlan.value = false
    pendingPlanKey.value = null
  }
}

const displayPrice = (plan, annual) => {
  const cents = annual ? plan.price_yearly : plan.price_monthly * 100
  const monthlyFromAnnual = annual ? Math.floor(plan.price_yearly / 12) * 100 : plan.price_monthly * 100

  return formatMoney(annual ? monthlyFromAnnual : cents)
}

const planMonthlyDisplay = plan => {
  if (annualToggle.value)
    return formatMoney(Math.floor(plan.price_yearly / 12) * 100)

  return formatMoney(plan.price_monthly * 100)
}

const yearlyTotal = plan => {
  return formatMoney(plan.price_yearly * 100)
}
</script>

<template>
  <div>
    <!-- Pending Approval Alert -->
    <VAlert
      v-if="hasPendingSubscription"
      type="warning"
      variant="tonal"
      icon="tabler-clock"
      class="mb-6"
      closable
    >
      <VAlertTitle class="mb-1">
        {{ t('companyPlan.pendingApproval') }}
      </VAlertTitle>
      <span>{{ t('companyPlan.pendingMessage') }}</span>
    </VAlert>

    <!-- Current Plan -->
    <VCard class="mb-6">
      <VCardTitle>{{ t('companyPlan.currentPlan') }}</VCardTitle>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <div class="mb-4">
              <h3 class="text-body-1 text-high-emphasis font-weight-medium mb-1">
                {{ t('companyPlan.yourCurrentPlanIs') }}
                <VChip
                  :color="planColor(currentPlanKey)"
                  label
                  size="small"
                  class="ms-1"
                >
                  {{ currentPlan?.name || currentPlanKey }}
                </VChip>
              </h3>
              <p class="text-body-1 mt-2">
                {{ currentPlan?.description || '' }}
              </p>
            </div>

            <div v-if="currentPlan">
              <h3 class="text-body-1 text-high-emphasis font-weight-medium mb-1">
                <span class="me-2">
                  {{ planMonthlyDisplay(currentPlan) }} / {{ t('common.monthly').toLowerCase() }}
                </span>
                <span
                  v-if="annualToggle && currentPlan.price_yearly > 0"
                  class="text-body-2 text-disabled"
                >
                  ({{ yearlyTotal(currentPlan) }} / {{ t('common.annually').toLowerCase() }})
                </span>
              </h3>
            </div>
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VAlert
              v-if="currentPlanKey === 'starter' && !hasPendingSubscription"
              icon="tabler-rocket"
              type="info"
              variant="tonal"
            >
              <VAlertTitle class="mb-1">
                {{ t('companyPlan.upgradeToUnlock') }}
              </VAlertTitle>
              <span>{{ t('companyPlan.upgradeToUnlockMessage') }}</span>
            </VAlert>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Plan Comparison -->
    <VCard>
      <VCardTitle>{{ t('companyPlan.comparePlans') }}</VCardTitle>
      <VCardText>
        <!-- Toggle -->
        <div class="d-flex font-weight-medium text-body-1 align-center justify-center mb-6">
          <VLabel
            for="plan-compare-toggle"
            class="me-3"
          >
            {{ t('common.monthly') }}
          </VLabel>
          <VSwitch
            id="plan-compare-toggle"
            v-model="annualToggle"
          >
            <template #label>
              <div class="text-body-1 font-weight-medium">
                {{ t('common.annually') }}
              </div>
            </template>
          </VSwitch>
        </div>

        <VSkeletonLoader
          v-if="loading"
          type="card"
        />

        <VRow v-else>
          <VCol
            v-for="plan in plans"
            :key="plan.key"
            cols="12"
            md="4"
          >
            <VCard
              flat
              border
              :class="plan.key === currentPlanKey ? 'border-primary border-opacity-100' : ''"
            >
              <VCardText
                style="block-size: 2.5rem;"
                class="text-end"
              >
                <VChip
                  v-if="plan.key === currentPlanKey"
                  label
                  color="success"
                  size="small"
                >
                  {{ t('common.current') }}
                </VChip>
                <VChip
                  v-else-if="plan.is_popular"
                  label
                  color="primary"
                  size="small"
                >
                  {{ t('common.popular') }}
                </VChip>
              </VCardText>

              <VCardText class="text-center">
                <h4 class="text-h4 mb-1">
                  {{ plan.name }}
                </h4>
                <p class="text-body-1 mb-4">
                  {{ plan.description }}
                </p>

                <div class="d-flex justify-center align-baseline pb-6">
                  <span class="text-h2 font-weight-medium text-primary">
                    {{ planMonthlyDisplay(plan) }}
                  </span>
                  <span class="text-body-1 font-weight-medium">{{ t('common.perMonth') }}</span>
                </div>

                <VList
                  density="compact"
                  class="card-list mb-4"
                >
                  <VListItem
                    v-for="feature in plan.feature_labels"
                    :key="feature"
                  >
                    <template #prepend>
                      <VIcon
                        size="8"
                        icon="tabler-circle-filled"
                        color="rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity))"
                      />
                    </template>
                    <VListItemTitle class="text-body-1">
                      {{ feature }}
                    </VListItemTitle>
                  </VListItem>
                </VList>

                <VBtn
                  v-if="plan.key === currentPlanKey"
                  block
                  color="success"
                  variant="tonal"
                  disabled
                >
                  {{ t('companyPlan.yourCurrentPlan') }}
                </VBtn>
                <VBtn
                  v-else
                  block
                  :color="plan.level > (currentPlan?.level ?? 0) ? 'primary' : 'secondary'"
                  :variant="plan.is_popular ? 'elevated' : 'tonal'"
                  :loading="changingPlan && pendingPlanKey === plan.key"
                  :disabled="hasPendingSubscription"
                  @click="requestPlanChange(plan.key)"
                >
                  {{ hasPendingSubscription ? t('companyPlan.pendingApproval') : (plan.level > (currentPlan?.level ?? 0) ? t('common.upgrade') : t('common.downgrade')) }}
                </VBtn>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Confirm Dialog -->
    <ConfirmDialog
      v-model:is-dialog-visible="isConfirmDialogVisible"
      :confirmation-question="t('companyPlan.confirmUpgrade', { name: plans.find(p => p.key === pendingPlanKey)?.name || '' })"
      :cancel-msg="t('companyPlan.requestCancelled')"
      :cancel-title="t('common.cancel')"
      :confirm-msg="t('companyPlan.requestSubmittedMessage')"
      :confirm-title="t('companyPlan.requestSubmitted')"
      @confirm="confirmPlanChange"
    />
  </div>
</template>

<style lang="scss" scoped>
.card-list {
  --v-card-list-gap: 0.5rem;
}
</style>
