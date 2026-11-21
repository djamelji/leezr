<script setup>
// ADR-834 — step D : plan (same plan grid as sign-up), resulting subscription status, never a payment.
// Commercial override only with the explicit permission (audited server-side, reason required).
import RegisterStepPlan from '@/pages/register/_RegisterStepPlan.vue'

defineProps({
  plans: { type: Array, required: true },
  outcome: { type: Object, default: null },
  canOverride: { type: Boolean, default: false },
  paidBlocked: { type: Boolean, default: false },
  overrideErrors: { type: Object, default: () => ({}) }, // ADR-834 fix : shown under the fields, never a silent block
})

const { t } = useI18n()

const planKey = defineModel('planKey', { type: String })
const annual = defineModel('annual', { type: Boolean })
const overrideEnabled = defineModel('overrideEnabled', { type: Boolean })
const override = defineModel('override', { type: Object })

const outcomeText = o => ({
  free: t('platformCompanyCreation.subscription.free'),
  trial: t('platformCompanyCreation.subscription.trial', { days: o.days }),
  override_trial: t('platformCompanyCreation.subscription.trial', { days: o.days }),
  override_complimentary: t('platformCompanyCreation.subscription.complimentary', { months: o.months }),
  trial_used: t('platformCompanyCreation.subscription.trialUsed'),
  no_trial: t('platformCompanyCreation.subscription.pendingPayment'),
}[o.kind])
</script>

<template>
  <RegisterStepPlan
    v-model:selected-plan="planKey"
    v-model:annual-toggle="annual"
    :plans="plans"
    i18n-prefix="platformCompanyCreation.form"
  />

  <VAlert
    v-if="outcome"
    :type="outcome.status === 'pending_payment' ? 'warning' : 'success'"
    variant="tonal"
    class="mt-6"
  >
    <div class="d-flex flex-wrap align-center gap-2 mb-1">
      <VChip
        size="small"
        :color="outcome.status === 'pending_payment' ? 'warning' : 'success'"
      >
        {{ t(`platformCompanyCreation.statuses.${outcome.status}`) }}
      </VChip>
      <span>{{ outcomeText(outcome) }}</span>
    </div>
    <div class="text-body-2">
      {{ t('platformCompanyCreation.subscription.noPayment') }}
    </div>
  </VAlert>

  <VAlert
    v-if="paidBlocked"
    type="warning"
    variant="tonal"
    class="mt-4"
  >
    {{ t('platformCompanyCreation.modules.paidNeedsTrial') }}
  </VAlert>

  <VCard
    v-if="canOverride && !plans.find(p => p.key === planKey)?.is_free"
    border
    flat
    class="mt-6"
  >
    <VCardText>
      <h6 class="text-h6 mb-1">
        {{ t('platformCompanyCreation.subscription.override.title') }}
      </h6>
      <p class="text-body-2 text-medium-emphasis mb-3">
        {{ t('platformCompanyCreation.subscription.override.audited') }}
      </p>
      <VSwitch
        v-model="overrideEnabled"
        :label="t('platformCompanyCreation.subscription.override.enable')"
        hide-details
        class="mb-4"
      />
      <template v-if="overrideEnabled">
        <VRadioGroup
          v-model="override.type"
          inline
          class="mb-2"
        >
          <VRadio
            value="trial"
            :label="t('platformCompanyCreation.subscription.override.typeTrial')"
          />
          <VRadio
            value="complimentary"
            :label="t('platformCompanyCreation.subscription.override.typeComplimentary')"
          />
        </VRadioGroup>
        <VRow>
          <VCol
            cols="12"
            sm="4"
          >
            <AppTextField
              v-if="override.type === 'trial'"
              v-model.number="override.trial_days"
              type="number"
              min="1"
              max="90"
              :label="t('platformCompanyCreation.subscription.override.trialDays')"
              :error-messages="overrideErrors.duration ? [t('platformCompanyCreation.subscription.override.errors.duration', overrideErrors.duration)] : []"
            />
            <AppTextField
              v-else
              v-model.number="override.complimentary_months"
              type="number"
              min="1"
              max="12"
              :label="t('platformCompanyCreation.subscription.override.months')"
              :error-messages="overrideErrors.duration ? [t('platformCompanyCreation.subscription.override.errors.duration', overrideErrors.duration)] : []"
            />
          </VCol>
          <VCol
            cols="12"
            sm="8"
          >
            <AppTextarea
              v-model="override.reason"
              rows="2"
              :label="t('platformCompanyCreation.subscription.override.reason')"
              :error-messages="overrideErrors.reason ? [t('platformCompanyCreation.subscription.override.errors.reason')] : []"
              :hint="t('platformCompanyCreation.subscription.override.reasonHint')"
              persistent-hint
            />
          </VCol>
        </VRow>
      </template>
    </VCardText>
  </VCard>
</template>
