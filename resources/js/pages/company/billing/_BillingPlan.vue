<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { useJobdomainStore } from '@/modules/company/jobdomain/jobdomain.store'
import { usePublicPlans } from '@/composables/usePublicPlans'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { formatMoney } from '@/utils/money'
import StatusChip from '@/core/components/StatusChip.vue'
import { cacheRemove } from '@/core/runtime/cache'
import { useAnalytics } from '@/composables/useAnalytics'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const settingsStore = useCompanySettingsStore()
const billingStore = useCompanyBillingStore()
const jobdomainStore = useJobdomainStore()
const { toast } = useAppToast()
const { plans, loading, fetchPlans } = usePublicPlans()
const { track } = useAnalytics()

// Accept ?suggest=plan_key from modules upsell navigation
const suggestedPlan = computed(() => route.query.suggest || null)

const memberQuota = computed(() => settingsStore.company?.member_quota ?? { current: 0, limit: null })
const storageInfo = computed(() => settingsStore.company?.storage ?? {})

const annualToggle = ref(true)
const changingPlan = ref(false)
const isPreviewDialogVisible = ref(false)
const pendingPlanKey = ref(null)
const checkingPending = ref(true)
const previewLoading = ref(false)
const previewError = ref(false)
const cancellingScheduledChange = ref(false)
const loadError = ref(false)
const dismissingRejected = ref(false)

const currentPlanKey = computed(() => auth.currentCompany?.plan_key ?? 'starter')
const currentPlan = computed(() => plans.value.find(p => p.key === currentPlanKey.value))
const currentInterval = computed(() => sub.value?.interval ?? 'monthly')
const selectedInterval = computed(() => annualToggle.value ? 'yearly' : 'monthly')
const scheduledChange = computed(() => sub.value?.scheduled_change ?? null)

const fetchData = async () => {
  loadError.value = false
  checkingPending.value = true

  try {
    await Promise.all([
      fetchPlans(),
      settingsStore.fetchCompany(),
      billingStore.fetchSubscription(),
      billingStore.fetchNextInvoicePreview(),
    ])

    // Initialize toggle from current subscription interval
    if (billingStore.subscription?.interval)
      annualToggle.value = billingStore.subscription.interval === 'yearly'

    // Show suggestion toast if navigated from modules page
    if (suggestedPlan.value) {
      const plan = plans.value.find(p => p.key === suggestedPlan.value)
      if (plan)
        toast(t('companyPlan.suggestUpgrade', { plan: plan.name }), 'info')
    }

    track('plan_change_viewed', { current_plan: currentPlanKey.value })
  }
  catch {
    loadError.value = true
  }
  finally {
    checkingPending.value = false
  }
}

onMounted(fetchData)

const sub = computed(() => billingStore.subscription)
const pendingSub = computed(() => billingStore.pendingSubscription)
const hasPendingSubscription = computed(() => pendingSub.value?.status === 'pending')

const dismissRejected = async () => {
  dismissingRejected.value = true
  try {
    await billingStore.dismissPendingSubscription()
    toast(t('companyPlan.rejectedDismissed'), 'success')
  }
  catch {
    toast(t('companyPlan.failedToSubmit'), 'error')
  }
  finally {
    dismissingRejected.value = false
  }
}

const formatDate = dateStr => {
  if (!dateStr) return null

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

const planColor = key => {
  const colors = { starter: 'secondary', pro: 'primary', business: 'warning' }

  return colors[key] || 'primary'
}

const changePreview = computed(() => billingStore.planChangePreview)

const requestPlanChange = async planKey => {
  // Block only when both plan key AND interval are the same
  if (planKey === currentPlanKey.value && selectedInterval.value === currentInterval.value) return

  pendingPlanKey.value = planKey
  previewLoading.value = true
  previewError.value = false
  isPreviewDialogVisible.value = true

  try {
    await billingStore.fetchPlanChangePreview(planKey, selectedInterval.value)

    const cp = billingStore.planChangePreview
    if (cp) {
      track('plan_change_previewed', {
        from: currentPlanKey.value,
        to: planKey,
        timing: cp.timing,
        amount_due: cp.amount_due,
      })
    }
  }
  catch {
    previewError.value = true
  }
  finally {
    previewLoading.value = false
  }
}

const cancelPreview = () => {
  isPreviewDialogVisible.value = false
  pendingPlanKey.value = null
  billingStore.clearPlanChangePreview()
}

const confirmPlanChange = async () => {
  if (!pendingPlanKey.value) return

  track('plan_change_confirmed', {
    from: currentPlanKey.value,
    to: pendingPlanKey.value,
    timing: changePreview.value?.timing,
  })

  changingPlan.value = true

  try {
    if (sub.value) {
      // Existing subscription → use plan-change endpoint (PlanChangeExecutor)
      const idempotencyKey = `plan-change-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
      const result = await $api('/billing/plan-change', {
        method: 'POST',
        body: {
          idempotency_key: idempotencyKey,
          to_plan_key: pendingPlanKey.value,
          to_interval: selectedInterval.value,
        },
      })

      isPreviewDialogVisible.value = false

      if (result.intent?.status === 'scheduled') {
        toast(t('companyPlan.changeScheduled'), 'info')
      }
      else {
        toast(t('companyPlan.changeApplied'), 'success')
      }
    }
    else {
      // No subscription → use checkout endpoint (initial subscription)
      const result = await $api('/billing/checkout', {
        method: 'POST',
        body: {
          plan_key: pendingPlanKey.value,
          billing_interval: selectedInterval.value,
        },
      })

      isPreviewDialogVisible.value = false

      if (result.mode === 'internal') {
        toast(result.message, 'info')
      }
      else if (result.mode === 'redirect' && result.redirect_url) {
        window.location.href = result.redirect_url
      }
    }
  }
  catch {
    toast(t('companyPlan.failedToSubmit'), 'error')
  }
  finally {
    changingPlan.value = false
    pendingPlanKey.value = null
    billingStore.clearPlanChangePreview()

    // Bust cache + refresh — real-time UI update without masking plan change result
    cacheRemove('auth:companies')
    await Promise.all([
      billingStore.fetchSubscription(),
      billingStore.fetchNextInvoicePreview(),
      auth.fetchMyCompanies(),
    ]).catch(() => {})
  }
}

const cancelScheduledChange = async () => {
  cancellingScheduledChange.value = true
  try {
    await billingStore.cancelScheduledPlanChange()
    toast(t('companyPlan.scheduledChange.cancelled'), 'success')
  }
  catch {
    toast(t('companyPlan.scheduledChange.cancelFailed'), 'error')
  }
  finally {
    cancellingScheduledChange.value = false
    cacheRemove('auth:companies')
    await Promise.all([
      billingStore.fetchSubscription(),
      auth.fetchMyCompanies(),
    ]).catch(() => {})
  }
}

const planButtonLabel = plan => {
  if (plan.key === currentPlanKey.value && selectedInterval.value !== currentInterval.value) {
    return selectedInterval.value === 'yearly'
      ? t('companyPlan.switchToAnnual')
      : t('companyPlan.switchToMonthly')
  }

  return plan.level > (currentPlan.value?.level ?? 0)
    ? t('common.upgrade')
    : t('common.downgrade')
}

const displayPrice = (plan, annual) => {
  const cents = annual ? plan.price_yearly : plan.price_monthly * 100
  const monthlyFromAnnual = annual ? Math.round(plan.price_yearly / 12 * 100) : plan.price_monthly * 100

  return formatMoney(annual ? monthlyFromAnnual : cents)
}

const planMonthlyDisplay = plan => {
  if (annualToggle.value)
    return formatMoney(Math.round(plan.price_yearly / 12 * 100))

  return formatMoney(plan.price_monthly * 100)
}

const yearlyTotal = plan => {
  return formatMoney(plan.price_yearly * 100)
}

const preview = computed(() => billingStore.nextInvoicePreview)

const estimatedInvoice = computed(() => {
  const p = preview.value
  if (!p) return null

  const addonsTotal = p.addons?.reduce((sum, a) => sum + (a.price || 0), 0) ?? 0
  const planPrice = p.plan?.price ?? 0

  return {
    planName: p.plan?.name,
    planPrice,
    addons: p.addons ?? [],
    addonsTotal,
    coupon: p.coupon ?? null,
    subtotal: p.subtotal ?? (planPrice + addonsTotal),
    taxAmount: p.tax_amount ?? 0,
    taxRateBps: p.tax_rate_bps ?? 0,
    total: p.total ?? (planPrice + addonsTotal),
    walletCredit: p.estimated_wallet_credit ?? 0,
    amountDue: p.estimated_amount_due ?? p.total ?? (planPrice + addonsTotal),
    currency: p.currency,
    nextBillingDate: p.next_billing_date,
    isEstimate: p.is_estimate ?? true,
  }
})
</script>

<template>
  <div>
    <!-- Load Error -->
    <VAlert
      v-if="loadError"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      {{ t('common.loadError') }}
      <template #append>
        <VBtn
          variant="text"
          size="small"
          @click="fetchData"
        >
          {{ t('common.retry') }}
        </VBtn>
      </template>
    </VAlert>

    <!-- Scheduled Plan Change Alert -->
    <VAlert
      v-if="scheduledChange"
      type="info"
      variant="tonal"
      icon="tabler-clock"
      class="mb-6"
    >
      <VAlertTitle class="mb-1">
        {{ t('companyPlan.scheduledChange.title') }}
      </VAlertTitle>
      <span>
        {{ t('companyPlan.scheduledChange.message', {
          plan: scheduledChange.to_plan_key,
          date: formatDate(scheduledChange.effective_at),
        }) }}
      </span>
      <template #append>
        <VBtn
          variant="outlined"
          color="error"
          size="small"
          :loading="cancellingScheduledChange"
          @click="cancelScheduledChange"
        >
          {{ t('companyPlan.scheduledChange.cancel') }}
        </VBtn>
      </template>
    </VAlert>

    <!-- Pending Upgrade Request (ADR-289) -->
    <VAlert
      v-if="pendingSub?.status === 'pending'"
      type="warning"
      variant="tonal"
      icon="tabler-hourglass"
      class="mb-6"
    >
      <VAlertTitle class="mb-1">
        {{ t('companyPlan.pendingApproval') }}
      </VAlertTitle>
      <span>{{ t('companyPlan.pendingMessage', { plan: pendingSub.plan_key }) }}</span>
    </VAlert>

    <!-- Rejected Upgrade Request (ADR-289) -->
    <VAlert
      v-if="pendingSub?.status === 'rejected'"
      type="error"
      variant="tonal"
      icon="tabler-x"
      class="mb-6"
    >
      <VAlertTitle class="mb-1">
        {{ t('companyPlan.rejectedTitle') }}
      </VAlertTitle>
      <span>{{ t('companyPlan.rejectedMessage', { plan: pendingSub.plan_key }) }}</span>
      <template #append>
        <VBtn
          variant="outlined"
          color="error"
          size="small"
          :loading="dismissingRejected"
          @click="dismissRejected"
        >
          {{ t('companyPlan.dismissRejected') }}
        </VBtn>
      </template>
    </VAlert>

    <!-- Current Plan + Industry Profile -->
    <VRow class="mb-6">
      <VCol
        cols="12"
        md="6"
      >
        <VCard class="h-100">
          <VCardTitle>
            <VIcon
              icon="tabler-credit-card"
              class="me-2"
            />
            {{ t('companyPlan.currentPlan') }}
          </VCardTitle>
          <VCardText>
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
                <template v-if="currentInterval === 'yearly'">
                  <span class="me-2">
                    {{ formatMoney(Math.round(currentPlan.price_yearly / 12 * 100)) }} / {{ t('common.monthly').toLowerCase() }}
                  </span>
                  <span class="text-body-2 text-disabled">
                    ({{ formatMoney(currentPlan.price_yearly * 100) }} / {{ t('common.annually').toLowerCase() }})
                  </span>
                </template>
                <template v-else>
                  {{ formatMoney(currentPlan.price_monthly * 100) }} / {{ t('common.monthly').toLowerCase() }}
                </template>
              </h3>
            </div>

            <!-- ADR-172: Usage meters -->
            <div
              v-if="currentPlan"
              class="mt-4"
            >
              <div class="mb-3">
                <div class="d-flex justify-space-between text-body-2 mb-1">
                  <span>{{ t('companyPlan.membersUsage') }}</span>
                  <span>
                    {{ memberQuota.current }}
                    /
                    {{ memberQuota.limit !== null ? memberQuota.limit : t('companyPlan.unlimited') }}
                  </span>
                </div>
                <VProgressLinear
                  :model-value="memberQuota.limit ? (memberQuota.current / memberQuota.limit) * 100 : 10"
                  :color="memberQuota.limit && memberQuota.current >= memberQuota.limit ? 'error' : 'primary'"
                  rounded
                  height="6"
                />
              </div>

              <div>
                <div class="d-flex justify-space-between text-body-2 mb-1">
                  <span>{{ t('companyPlan.storageUsage') }}</span>
                  <span>
                    {{ storageInfo.used_display || '0 MB' }}
                    /
                    {{ storageInfo.limit_display || '—' }}
                  </span>
                </div>
                <VProgressLinear
                  :model-value="storageInfo.used_percent ?? 0"
                  :color="(storageInfo.used_percent ?? 0) >= 90 ? 'error' : 'primary'"
                  rounded
                  height="6"
                />
              </div>
            </div>

            <!-- Subscription period details -->
            <div
              v-if="sub"
              class="mt-4"
            >
              <VDivider class="mb-4" />
              <div class="d-flex flex-column gap-2">
                <div
                  v-if="sub.current_period_start && sub.current_period_end"
                  class="d-flex align-center gap-2"
                >
                  <VIcon
                    icon="tabler-calendar"
                    size="18"
                  />
                  <span class="text-body-2">
                    {{ t('companyPlan.currentPeriod') }}:
                    <strong>{{ formatDate(sub.current_period_start) }} — {{ formatDate(sub.current_period_end) }}</strong>
                  </span>
                </div>

                <div
                  v-if="sub.current_period_end"
                  class="d-flex align-center gap-2"
                >
                  <VIcon
                    icon="tabler-refresh"
                    size="18"
                  />
                  <span class="text-body-2">
                    {{ t('companyPlan.nextRenewal') }}:
                    <strong>{{ formatDate(sub.current_period_end) }}</strong>
                  </span>
                </div>

                <div
                  v-if="sub.trial_ends_at"
                  class="d-flex align-center gap-2"
                >
                  <VIcon
                    icon="tabler-clock"
                    size="18"
                    color="warning"
                  />
                  <span class="text-body-2">
                    {{ t('companyPlan.trialEnds') }}:
                    <strong>{{ formatDate(sub.trial_ends_at) }}</strong>
                  </span>
                </div>

                <div
                  v-if="sub.status"
                  class="d-flex align-center gap-2"
                >
                  <VIcon
                    icon="tabler-circle-check"
                    size="18"
                  />
                  <span class="text-body-2">
                    {{ t('companyPlan.subscriptionStatus') }}:
                    <StatusChip
                      :status="sub.status"
                      domain="subscription"
                      size="x-small"
                    >
                      {{ t(`subscriptionStatus.${sub.status}`) }}
                    </StatusChip>
                  </span>
                </div>
              </div>
            </div>

            <VAlert
              v-if="currentPlanKey === 'starter' && !hasPendingSubscription"
              icon="tabler-rocket"
              type="info"
              variant="tonal"
              class="mt-4"
            >
              <VAlertTitle class="mb-1">
                {{ t('companyPlan.upgradeToUnlock') }}
              </VAlertTitle>
              <span>{{ t('companyPlan.upgradeToUnlockMessage') }}</span>
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="6"
      >
        <VCard class="h-100">
          <VCardTitle>
            <VIcon
              icon="tabler-briefcase"
              class="me-2"
            />
            {{ t('jobdomain.title') }}
          </VCardTitle>
          <VCardText>
            <div
              v-if="jobdomainStore.assigned"
              class="mb-4"
            >
              <h3 class="text-body-1 text-high-emphasis font-weight-medium mb-1">
                {{ t('jobdomain.currentProfile') }}
                <VChip
                  color="primary"
                  label
                  size="small"
                  class="ms-1"
                >
                  {{ jobdomainStore.jobdomain?.label }}
                </VChip>
              </h3>
            </div>

            <VAlert
              v-if="jobdomainStore.assigned"
              type="warning"
              variant="tonal"
              icon="tabler-lock"
            >
              {{ t('jobdomain.immutableNotice') }}
            </VAlert>

            <VAlert
              v-else
              type="info"
              variant="tonal"
              icon="tabler-info-circle"
            >
              {{ t('jobdomain.noProfileAssigned') }}
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

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
              :class="{
                'border-primary border-opacity-100': plan.key === currentPlanKey,
                'border-warning border-opacity-100': suggestedPlan && plan.key === suggestedPlan && plan.key !== currentPlanKey,
              }"
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
                  v-else-if="suggestedPlan && plan.key === suggestedPlan"
                  label
                  color="warning"
                  size="small"
                >
                  {{ t('companyPlan.suggested') }}
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
                  <span class="text-caption text-disabled ms-1">{{ t('common.exclTax') }}</span>
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

                <!-- ADR-287: Trial badge — only for new subscriptions, not upgrades/downgrades -->
                <VChip
                  v-if="plan.trial_days > 0 && !sub"
                  color="info"
                  variant="tonal"
                  size="small"
                  prepend-icon="tabler-clock"
                  class="mb-3"
                >
                  {{ t('companyPlan.freeTrialDays', { days: plan.trial_days }) }}
                </VChip>

                <!-- ADR-172: Concrete limits -->
                <div class="text-start mb-4">
                  <div class="d-flex align-center gap-2 mb-1">
                    <VIcon
                      icon="tabler-users"
                      size="18"
                    />
                    <span class="text-body-2">
                      {{ t('companyPlan.membersLimit') }}:
                      <strong>{{ plan.limits?.members ? t('companyPlan.nMembers', { n: plan.limits.members }) : t('companyPlan.unlimited') }}</strong>
                    </span>
                  </div>
                  <div class="d-flex align-center gap-2">
                    <VIcon
                      icon="tabler-database"
                      size="18"
                    />
                    <span class="text-body-2">
                      {{ t('companyPlan.storageLimit') }}:
                      <strong>{{ plan.limits?.storage_quota_gb ? t('companyPlan.nGb', { n: plan.limits.storage_quota_gb }) : t('companyPlan.unlimited') }}</strong>
                    </span>
                  </div>
                </div>

                <VBtn
                  v-if="plan.key === currentPlanKey && selectedInterval === currentInterval"
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
                  :color="plan.key === currentPlanKey
                    ? 'info'
                    : (plan.level > (currentPlan?.level ?? 0) ? 'primary' : 'secondary')"
                  :variant="plan.is_popular ? 'elevated' : 'tonal'"
                  :loading="changingPlan && pendingPlanKey === plan.key"
                  :disabled="hasPendingSubscription"
                  @click="requestPlanChange(plan.key)"
                >
                  {{ planButtonLabel(plan) }}
                </VBtn>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Estimated Next Invoice -->
    <VCard
      v-if="estimatedInvoice"
      class="mt-6"
    >
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            size="40"
            rounded
          >
            <VIcon icon="tabler-file-invoice" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companyPlan.estimatedInvoice') }}</VCardTitle>
        <VCardSubtitle v-if="estimatedInvoice.nextBillingDate">
          {{ formatDate(estimatedInvoice.nextBillingDate) }}
        </VCardSubtitle>
      </VCardItem>

      <VCardText class="pa-0">
        <VList density="compact">
          <VListItem v-if="estimatedInvoice.planName">
            <VListItemTitle class="font-weight-medium">
              {{ estimatedInvoice.planName }}
            </VListItemTitle>
            <VListItemSubtitle>
              {{ t('companyBilling.nextInvoice.planLabel') }}
            </VListItemSubtitle>

            <template #append>
              <span class="font-weight-medium">
                {{ formatMoney(estimatedInvoice.planPrice, { currency: estimatedInvoice.currency }) }}
              </span>
            </template>
          </VListItem>

          <VListItem
            v-for="addon in estimatedInvoice.addons"
            :key="addon.module_key"
          >
            <VListItemTitle class="font-weight-medium">
              {{ addon.name }}
            </VListItemTitle>
            <VListItemSubtitle>
              {{ t('companyBilling.nextInvoice.addonLabel') }}
            </VListItemSubtitle>

            <template #append>
              <span class="font-weight-medium">
                +{{ formatMoney(addon.price, { currency: estimatedInvoice.currency }) }}
              </span>
            </template>
          </VListItem>

          <!-- Coupon discount -->
          <VListItem v-if="estimatedInvoice.coupon">
            <VListItemTitle class="font-weight-medium text-success">
              <VIcon icon="tabler-ticket" size="16" start />
              {{ t('companyBilling.nextInvoice.couponLabel') }}: {{ estimatedInvoice.coupon.code }}
            </VListItemTitle>

            <template #append>
              <span class="font-weight-medium text-success">
                -{{ formatMoney(estimatedInvoice.coupon.discount, { currency: estimatedInvoice.currency }) }}
              </span>
            </template>
          </VListItem>

          <VDivider />

          <VListItem>
            <VListItemTitle class="font-weight-medium">
              {{ t('companyBilling.nextInvoice.subtotal') }}
            </VListItemTitle>

            <template #append>
              <span class="font-weight-medium">
                {{ formatMoney(estimatedInvoice.subtotal, { currency: estimatedInvoice.currency }) }}
              </span>
            </template>
          </VListItem>

          <VListItem v-if="estimatedInvoice.taxAmount > 0">
            <VListItemTitle class="text-body-2 text-disabled">
              {{ t('companyBilling.nextInvoice.tax') }}
              <span v-if="estimatedInvoice.taxRateBps">({{ (estimatedInvoice.taxRateBps / 100).toFixed(1) }}%)</span>
            </VListItemTitle>

            <template #append>
              <span class="text-body-2">
                +{{ formatMoney(estimatedInvoice.taxAmount, { currency: estimatedInvoice.currency }) }}
              </span>
            </template>
          </VListItem>

          <!-- Tax exemption -->
          <VListItem v-if="preview?.tax_exemption_reason">
            <VListItemTitle>
              <VChip
                color="info"
                variant="tonal"
                size="small"
              >
                {{ t('billing.tax_exemption.' + preview.tax_exemption_reason) }}
              </VChip>
            </VListItemTitle>
          </VListItem>

          <VListItem v-if="estimatedInvoice.walletCredit > 0">
            <VListItemTitle class="text-body-2 text-success">
              {{ t('companyBilling.nextInvoice.walletCredit') }}
            </VListItemTitle>

            <template #append>
              <span class="text-body-2 text-success">
                -{{ formatMoney(estimatedInvoice.walletCredit, { currency: estimatedInvoice.currency }) }}
              </span>
            </template>
          </VListItem>

          <VDivider />

          <VListItem>
            <VListItemTitle class="text-h6 font-weight-bold">
              {{ t('companyBilling.nextInvoice.estimatedAmountDue') }}
            </VListItemTitle>

            <template #append>
              <span class="text-h6 font-weight-bold">
                {{ formatMoney(estimatedInvoice.amountDue, { currency: estimatedInvoice.currency }) }}
              </span>
            </template>
          </VListItem>
        </VList>
      </VCardText>

      <VCardText
        v-if="estimatedInvoice.isEstimate"
        class="pt-0"
      >
        <p class="text-caption text-disabled mb-0">
          {{ t('companyBilling.nextInvoice.estimateDisclaimer') }}
        </p>
      </VCardText>
    </VCard>

    <!-- Plan Change Preview Dialog -->
    <VDialog
      v-model="isPreviewDialogVisible"
      max-width="560"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center gap-2 pa-5 pb-3">
          <VAvatar
            :color="changePreview?.is_upgrade ? 'success' : 'warning'"
            variant="tonal"
            size="40"
            rounded
          >
            <VIcon :icon="changePreview?.is_upgrade ? 'tabler-trending-up' : 'tabler-trending-down'" />
          </VAvatar>
          {{ t('companyPlan.planChangePreview.title') }}
        </VCardTitle>

        <VCardText v-if="previewLoading" class="text-center pa-8">
          <VProgressCircular indeterminate color="primary" class="mb-3" />
          <p class="text-body-1">{{ t('companyPlan.planChangePreview.loading') }}</p>
        </VCardText>

        <VCardText v-else-if="previewError" class="text-center pa-8">
          <VIcon icon="tabler-alert-triangle" color="error" size="48" class="mb-3" />
          <p class="text-body-1">{{ t('companyPlan.planChangePreview.errorLoading') }}</p>
        </VCardText>

        <VCardText v-else-if="changePreview" class="pa-5 pt-0">
          <!-- From → To -->
          <div class="d-flex align-center justify-space-between mb-4 pa-3 rounded" style="background: rgba(var(--v-theme-on-surface), 0.04);">
            <div class="text-center">
              <p class="text-caption text-disabled mb-1">{{ t('companyPlan.planChangePreview.from') }}</p>
              <VChip color="secondary" label size="small">{{ changePreview.from_plan.name }}</VChip>
              <p class="text-caption mt-1">{{ formatMoney(changePreview.from_plan.price, { currency: changePreview.currency }) }}/{{ changePreview.from_plan.interval === 'yearly' ? t('common.annually').toLowerCase() : t('common.monthly').toLowerCase() }}</p>
            </div>
            <VIcon icon="tabler-arrow-right" size="20" />
            <div class="text-center">
              <p class="text-caption text-disabled mb-1">{{ t('companyPlan.planChangePreview.to') }}</p>
              <VChip :color="changePreview.is_upgrade ? 'success' : 'warning'" label size="small">{{ changePreview.to_plan.name }}</VChip>
              <p class="text-caption mt-1">{{ formatMoney(changePreview.to_plan.price, { currency: changePreview.currency }) }}/{{ changePreview.to_plan.interval === 'yearly' ? t('common.annually').toLowerCase() : t('common.monthly').toLowerCase() }}</p>
            </div>
          </div>

          <!-- Timing + Fiscal context -->
          <div class="d-flex flex-column gap-2 mb-4">
            <div class="d-flex align-center gap-2">
              <VIcon icon="tabler-clock" size="18" />
              <span class="text-body-2">
                {{ t('companyPlan.planChangePreview.timing') }}:
                <strong>{{ changePreview.timing === 'immediate' ? t('companyPlan.planChangePreview.immediate') : t('companyPlan.planChangePreview.endOfPeriod') }}</strong>
              </span>
            </div>
            <div class="d-flex align-center gap-2">
              <VIcon icon="tabler-receipt-tax" size="18" />
              <span class="text-body-2">
                {{ t('companyPlan.planChangePreview.taxInfo') }}:
                <strong>{{ t('companyPlan.planChangePreview.taxRate', { rate: (changePreview.tax_rate_bps / 100).toFixed(1), mode: changePreview.tax_mode }) }}</strong>
              </span>
            </div>
            <div v-if="changePreview.tax_exemption_reason" class="d-flex align-center gap-2">
              <VIcon icon="tabler-certificate" size="18" />
              <VChip
                color="info"
                variant="tonal"
                size="small"
              >
                {{ t('billing.tax_exemption.' + changePreview.tax_exemption_reason) }}
              </VChip>
            </div>
            <div v-if="changePreview.market_name || changePreview.legal_status_name" class="d-flex align-center gap-2">
              <VIcon icon="tabler-map-pin" size="18" />
              <span class="text-body-2 text-disabled">
                {{ [changePreview.market_name, changePreview.legal_status_name].filter(Boolean).join(' · ') }}
              </span>
            </div>
          </div>

          <!-- Active coupon info -->
          <VAlert
            v-if="changePreview.active_coupon"
            type="success"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            <div class="d-flex align-center gap-2">
              <VIcon icon="tabler-ticket" size="18" />
              <span class="text-body-2">
                {{ t('companyPlan.planChangePreview.couponKept', { code: changePreview.active_coupon.code }) }}
              </span>
            </div>
          </VAlert>

          <!-- Proration breakdown (immediate only) -->
          <template v-if="changePreview.timing === 'immediate' && changePreview.proration">
            <VDivider class="mb-3" />
            <p class="text-subtitle-2 font-weight-medium mb-2">{{ t('companyPlan.planChangePreview.proration') }}</p>
            <p class="text-caption text-disabled mb-2">{{ t('companyPlan.planChangePreview.daysRemaining', { days: changePreview.proration.days_remaining }) }}</p>

            <VList density="compact" class="pa-0">
              <VListItem v-if="changePreview.proration.credit_old_plan > 0">
                <VListItemTitle class="text-body-2 text-success">
                  {{ t('companyPlan.planChangePreview.creditOldPlan', { name: changePreview.from_plan.name }) }}
                </VListItemTitle>
                <template #append>
                  <span class="text-body-2 text-success">-{{ formatMoney(changePreview.proration.credit_old_plan, { currency: changePreview.currency }) }}</span>
                </template>
              </VListItem>

              <VListItem v-if="changePreview.proration.charge_new_plan > 0">
                <VListItemTitle class="text-body-2">
                  {{ t('companyPlan.planChangePreview.chargeNewPlan', { name: changePreview.to_plan.name }) }}
                </VListItemTitle>
                <template #append>
                  <span class="text-body-2">+{{ formatMoney(changePreview.proration.charge_new_plan, { currency: changePreview.currency }) }}</span>
                </template>
              </VListItem>

              <VDivider />

              <VListItem>
                <VListItemTitle class="font-weight-medium">
                  {{ t('companyPlan.planChangePreview.prorationNet') }}
                </VListItemTitle>
                <template #append>
                  <span class="font-weight-medium" :class="changePreview.proration.net > 0 ? '' : 'text-success'">
                    {{ changePreview.proration.net > 0 ? '+' : '' }}{{ formatMoney(changePreview.proration.net, { currency: changePreview.currency }) }}
                  </span>
                </template>
              </VListItem>
            </VList>
          </template>

          <!-- Addon impact -->
          <template v-if="changePreview.addons?.length">
            <VDivider class="my-3" />
            <p class="text-subtitle-2 font-weight-medium mb-2">{{ t('companyPlan.planChangePreview.addonsImpact') }}</p>
            <VList density="compact" class="pa-0">
              <VListItem v-for="addon in changePreview.addons" :key="addon.module_key">
                <VListItemTitle class="text-body-2">{{ addon.name }}</VListItemTitle>
                <template #append>
                  <span v-if="addon.difference !== 0" class="text-body-2" :class="addon.difference > 0 ? '' : 'text-success'">
                    {{ formatMoney(addon.current_amount, { currency: changePreview.currency }) }} → {{ formatMoney(addon.new_amount, { currency: changePreview.currency }) }}
                  </span>
                  <span v-else class="text-body-2 text-disabled">{{ formatMoney(addon.current_amount, { currency: changePreview.currency }) }}</span>
                </template>
              </VListItem>
            </VList>
          </template>

          <!-- Due now (immediate) -->
          <template v-if="changePreview.timing === 'immediate'">
            <VDivider class="my-3" />
            <p class="text-subtitle-2 font-weight-medium mb-2">{{ t('companyPlan.planChangePreview.dueNow') }}</p>
            <VList density="compact" class="pa-0">
              <VListItem>
                <VListItemTitle class="text-body-2">{{ t('companyPlan.planChangePreview.subtotal') }}</VListItemTitle>
                <template #append>
                  <span class="text-body-2">{{ formatMoney(changePreview.immediate.subtotal, { currency: changePreview.currency }) }}</span>
                </template>
              </VListItem>

              <VListItem>
                <VListItemTitle class="text-body-2 text-disabled">
                  {{ t('companyPlan.planChangePreview.tax') }}
                  <span v-if="changePreview.immediate.tax_rate_bps">({{ (changePreview.immediate.tax_rate_bps / 100).toFixed(1) }}%)</span>
                </VListItemTitle>
                <template #append>
                  <span class="text-body-2">{{ changePreview.immediate.tax_amount > 0 ? '+' : '' }}{{ formatMoney(changePreview.immediate.tax_amount, { currency: changePreview.currency }) }}</span>
                </template>
              </VListItem>

              <!-- Wallet deduction (upgrade: wallet pays part of amount due) -->
              <VListItem v-if="changePreview.immediate.wallet_deduction > 0">
                <VListItemTitle class="text-body-2 text-success">{{ t('companyPlan.planChangePreview.walletDeduction') }}</VListItemTitle>
                <template #append>
                  <span class="text-body-2 text-success">-{{ formatMoney(changePreview.immediate.wallet_deduction, { currency: changePreview.currency }) }}</span>
                </template>
              </VListItem>

              <!-- Credit to wallet (downgrade: unused credit goes to wallet) -->
              <VListItem v-if="changePreview.immediate.wallet_credit_added > 0">
                <VListItemTitle class="text-body-2 text-success">{{ t('companyPlan.planChangePreview.walletCreditAdded') }}</VListItemTitle>
                <template #append>
                  <span class="text-body-2 text-success">+{{ formatMoney(changePreview.immediate.wallet_credit_added, { currency: changePreview.currency }) }}</span>
                </template>
              </VListItem>

              <VDivider />

              <VListItem>
                <VListItemTitle class="text-h6 font-weight-bold">{{ t('companyPlan.planChangePreview.estimatedAmountDue') }}</VListItemTitle>
                <template #append>
                  <span class="text-h6 font-weight-bold">{{ formatMoney(changePreview.immediate.estimated_amount_due, { currency: changePreview.currency }) }}</span>
                </template>
              </VListItem>
            </VList>
          </template>

          <!-- No charge (end_of_period) -->
          <template v-else>
            <VDivider class="my-3" />
            <VAlert type="info" variant="tonal" icon="tabler-info-circle" density="compact">
              {{ t('companyPlan.planChangePreview.noImmediateCharge') }}
            </VAlert>
          </template>

          <!-- Wallet balance (always shown if > 0) -->
          <template v-if="changePreview.wallet_balance > 0">
            <div class="d-flex align-center gap-2 mt-3">
              <VIcon icon="tabler-wallet" size="18" color="success" />
              <span class="text-body-2">
                {{ t('companyPlan.planChangePreview.walletBalance') }}:
                <strong class="text-success">{{ formatMoney(changePreview.wallet_balance, { currency: changePreview.currency }) }}</strong>
              </span>
            </div>
          </template>

          <!-- Next period preview -->
          <VDivider class="my-3" />
          <p class="text-subtitle-2 font-weight-medium mb-2">{{ t('companyPlan.planChangePreview.nextPeriod') }}</p>
          <VList density="compact" class="pa-0">
            <VListItem>
              <VListItemTitle class="text-body-2">{{ t('companyPlan.planChangePreview.subtotalHT') }}</VListItemTitle>
              <template #append>
                <span class="text-body-2">{{ formatMoney(changePreview.next_period.subtotal, { currency: changePreview.currency }) }}</span>
              </template>
            </VListItem>

            <VListItem v-if="changePreview.next_period.coupon_discount">
              <VListItemTitle class="text-body-2 text-success">
                {{ t('companyPlan.planChangePreview.couponDiscount') }}
              </VListItemTitle>
              <template #append>
                <span class="text-body-2 text-success">{{ t('companyPlan.planChangePreview.includedInSubtotal') }}</span>
              </template>
            </VListItem>

            <VListItem>
              <VListItemTitle class="text-body-2 text-disabled">
                {{ t('companyPlan.planChangePreview.tax') }}
                <span v-if="changePreview.next_period.tax_rate_bps">({{ (changePreview.next_period.tax_rate_bps / 100).toFixed(1) }}%)</span>
              </VListItemTitle>
              <template #append>
                <span class="text-body-2">{{ changePreview.next_period.tax_amount > 0 ? '+' : '' }}{{ formatMoney(changePreview.next_period.tax_amount, { currency: changePreview.currency }) }}</span>
              </template>
            </VListItem>

            <VDivider />

            <VListItem>
              <VListItemTitle class="font-weight-medium">
                {{ t('companyPlan.planChangePreview.totalTTC') }} / {{ changePreview.next_period.interval === 'yearly' ? t('common.annually').toLowerCase() : t('common.monthly').toLowerCase() }}
              </VListItemTitle>
              <template #append>
                <span class="font-weight-medium">{{ formatMoney(changePreview.next_period.total, { currency: changePreview.currency }) }}</span>
              </template>
            </VListItem>
          </VList>

          <p class="text-caption text-disabled mt-3 mb-0">
            {{ t('companyBilling.nextInvoice.estimateDisclaimer') }}
          </p>
        </VCardText>

        <VCardActions class="pa-5 pt-2">
          <VSpacer />
          <VBtn variant="tonal" color="secondary" @click="cancelPreview">
            {{ t('companyPlan.planChangePreview.cancel') }}
          </VBtn>
          <VBtn
            :color="changePreview?.is_upgrade ? 'success' : 'warning'"
            :loading="changingPlan"
            :disabled="previewLoading || previewError"
            @click="confirmPlanChange"
          >
            {{ t('companyPlan.planChangePreview.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style lang="scss" scoped>
.card-list {
  --v-card-list-gap: 0.5rem;
}
</style>
