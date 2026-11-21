<script setup>
// ADR-834 — start of service of a company (created ≠ in service) : Platform set-up items + the owner's own
// first steps, from the single checklist computation (CompanyOnboardingChecklist). Resumable : re-send the
// administrator invitation, complete the profile.
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppToast } from '@/composables/useAppToast'
import { formatDate } from '@/utils/datetime'
import { STAGES, STAGE_COLORS, STAGE_ICONS } from '@/modules/platform-admin/companies/companyCreationRules'

const props = defineProps({
  companyId: { type: [Number, String], required: true },
})

const emit = defineEmits(['goTab'])

const { t } = useI18n()
const store = usePlatformCompaniesStore()
const platformAuth = usePlatformAuthStore()
const { toast } = useAppToast()

const state = ref(null)
const loading = ref(true)
const resending = ref(false)
// same rights as the APIs : re-send = creator (create_companies) or manage_companies ; profile = manage_companies ;
// billing tab = view_companies
const canResend = computed(() => platformAuth.hasPermission('create_companies') || platformAuth.hasPermission('manage_companies'))
const canEditProfile = computed(() => platformAuth.hasPermission('manage_companies'))
const canSeeBilling = computed(() => platformAuth.hasPermission('view_companies'))

// stages of THIS company : the payment stage only when the subscription actually awaits payment
const stages = computed(() => {
  if (!state.value)
    return []
  const awaitingPayment = item('subscription')?.detail.status === 'pending_payment'
  const list = STAGES.filter(s => s !== 'awaiting_payment' || awaitingPayment || state.value.stage === 'awaiting_payment')
  const current = list.indexOf(state.value.stage)

  return list.map((key, i) => ({ key, done: state.value.in_service || i < current, current: i === current }))
})

const load = async () => {
  loading.value = true
  try {
    state.value = await store.fetchCompanyOnboarding(props.companyId)
  }
  finally {
    loading.value = false
  }
}

onMounted(load)

const resend = async () => {
  resending.value = true
  try {
    const res = await store.resendAdministratorInvitation(props.companyId)

    state.value = res.onboarding
    toast(t('platformCompanyCreation.onboarding.resent'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyCreation.errors.generic'), 'error')
  }
  finally {
    resending.value = false
  }
}

const item = key => state.value?.items.find(i => i.key === key)
const kindLabel = kind => t(`platformCompanyCreation.initialization.kinds.${kind}`)
const camel = key => key.replace(/_([a-z])/g, (_, c) => c.toUpperCase())
</script>

<template>
  <VSkeletonLoader
    v-if="loading"
    type="card, list-item-two-line@4"
  />

  <div v-else-if="state">
    <VCard class="mb-6">
      <VCardText class="d-flex flex-wrap align-center gap-4">
        <VAvatar
          :color="state.in_service ? 'success' : 'warning'"
          variant="tonal"
          size="48"
        >
          <VIcon :icon="state.in_service ? 'tabler-circle-check' : 'tabler-progress'" />
        </VAvatar>
        <div class="flex-grow-1">
          <h5 class="text-h5">
            {{ state.in_service ? t('platformCompanyCreation.onboarding.inService') : t('platformCompanyCreation.onboarding.notInService') }}
          </h5>
          <p
            v-if="state.creation"
            class="text-body-2 mb-0"
          >
            {{ t('platformCompanyCreation.onboarding.createdBy', { date: formatDate(state.creation.created_at), name: state.creation.created_by || '—' }) }}
          </p>
        </div>
        <div class="text-body-2 text-medium-emphasis">
          {{ t('platformCompanyCreation.onboarding.progress', { done: state.completed_count, total: state.total_count }) }}
        </div>
      </VCardText>
      <VDivider />
      <VCardText>
        <ol
          class="d-flex flex-wrap gap-3 ps-0 mb-0"
          style="list-style: none;"
          :aria-label="t('platformCompanyCreation.onboarding.title')"
        >
          <li
            v-for="s in stages"
            :key="s.key"
            :aria-current="s.current ? 'step' : undefined"
          >
            <VChip
              :color="s.done || s.current ? STAGE_COLORS[s.key] : 'secondary'"
              :variant="s.current ? 'elevated' : 'tonal'"
              :prepend-icon="s.done ? 'tabler-check' : STAGE_ICONS[s.key]"
            >
              {{ t(`platformCompanyCreation.stages.${s.key}`) }}
            </VChip>
          </li>
        </ol>
        <p class="text-body-2 text-medium-emphasis mt-3 mb-0">
          {{ t(`platformCompanyCreation.stageHints.${state.stage}`) }}
        </p>
      </VCardText>
    </VCard>

    <VCard :title="t('platformCompanyCreation.onboarding.title')">
      <VList lines="two">
        <!-- Administrator -->
        <VListItem v-if="item('administrator')">
          <template #prepend>
            <VIcon
              :icon="item('administrator').status === 'done' ? 'tabler-circle-check' : 'tabler-clock'"
              :color="item('administrator').status === 'done' ? 'success' : 'warning'"
            />
          </template>
          <VListItemTitle>{{ t('platformCompanyCreation.onboarding.items.administrator') }}</VListItemTitle>
          <VListItemSubtitle>
            {{ t(`platformCompanyCreation.onboarding.adminStates.${item('administrator').detail.state}`) }}
            <span v-if="item('administrator').detail.email"> · {{ item('administrator').detail.name }} ({{ item('administrator').detail.email }})</span>
          </VListItemSubtitle>
          <template
            v-if="item('administrator').status !== 'done' && canResend && item('administrator').detail.state !== 'missing'"
            #append
          >
            <VBtn
              size="small"
              variant="tonal"
              prepend-icon="tabler-send"
              :loading="resending"
              @click="resend"
            >
              {{ t('platformCompanyCreation.onboarding.resend') }}
            </VBtn>
          </template>
        </VListItem>

        <!-- Required fields -->
        <VListItem v-if="item('required_fields')">
          <template #prepend>
            <VIcon
              :icon="item('required_fields').status === 'done' ? 'tabler-circle-check' : 'tabler-clock'"
              :color="item('required_fields').status === 'done' ? 'success' : 'warning'"
            />
          </template>
          <VListItemTitle>{{ t('platformCompanyCreation.onboarding.items.required_fields') }}</VListItemTitle>
          <VListItemSubtitle>
            {{ item('required_fields').detail.missing.length
              ? t('platformCompanyCreation.onboarding.missingFields', { fields: item('required_fields').detail.missing.map(f => f.label).join(', ') })
              : t('platformCompanyCreation.onboarding.fieldsComplete') }}
          </VListItemSubtitle>
          <template
            v-if="item('required_fields').status !== 'done' && canEditProfile"
            #append
          >
            <VBtn
              size="small"
              variant="tonal"
              prepend-icon="tabler-pencil"
              @click="emit('goTab', 'configuration')"
            >
              {{ t('platformCompanyCreation.onboarding.editProfile') }}
            </VBtn>
          </template>
        </VListItem>

        <!-- Subscription -->
        <VListItem v-if="item('subscription')">
          <template #prepend>
            <VIcon
              :icon="item('subscription').status === 'done' ? 'tabler-circle-check' : 'tabler-clock'"
              :color="item('subscription').status === 'done' ? 'success' : 'warning'"
            />
          </template>
          <VListItemTitle>{{ t('platformCompanyCreation.onboarding.items.subscription') }}</VListItemTitle>
          <VListItemSubtitle>
            {{ t(`platformCompanyCreation.statuses.${item('subscription').detail.status}`) }}
            <span v-if="item('subscription').detail.trial_ends_at"> · {{ formatDate(item('subscription').detail.trial_ends_at) }}</span>
          </VListItemSubtitle>
          <template
            v-if="canSeeBilling"
            #append
          >
            <VBtn
              size="small"
              variant="text"
              @click="emit('goTab', 'billing')"
            >
              {{ t('platformCompanyDetail.billing.tab') }}
            </VBtn>
          </template>
        </VListItem>

        <!-- Initial decisions -->
        <VListItem v-if="item('initial_decisions')">
          <template #prepend>
            <VIcon
              :icon="item('initial_decisions').status === 'done' ? 'tabler-circle-check' : 'tabler-info-circle'"
              :color="item('initial_decisions').status === 'done' ? 'success' : 'info'"
            />
          </template>
          <VListItemTitle>{{ t('platformCompanyCreation.onboarding.items.initial_decisions') }}</VListItemTitle>
          <VListItemSubtitle>
            {{ item('initial_decisions').detail.pending.length
              ? t('platformCompanyCreation.onboarding.pendingDecisions', { items: item('initial_decisions').detail.pending.map(kindLabel).join(', ') })
              : t('platformCompanyCreation.onboarding.decisionsDone') }}
          </VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>

    <VCard
      class="mt-6"
      :title="t('platformCompanyCreation.onboarding.ownerSteps')"
    >
      <VList density="compact">
        <VListItem
          v-for="step in state.owner_steps"
          :key="step.key"
        >
          <template #prepend>
            <VIcon
              :icon="step.completed ? 'tabler-circle-check' : 'tabler-circle'"
              :color="step.completed ? 'success' : 'secondary'"
            />
          </template>
          <VListItemTitle>{{ t(`onboarding.steps.${camel(step.key)}.label`) }}</VListItemTitle>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
