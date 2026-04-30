<script setup>
/**
 * ADR-383: Onboarding widget — owner-only, dismissible, 6 steps.
 * Rendered OUTSIDE the dashboard grid — height adapts to content.
 * Recoverable: when dismissed, shows a slim banner to reopen (unless all completed).
 */
import congoImg from '@images/illustrations/congo-illustration.png'
import { useAuthStore } from '@/core/stores/auth'
import { $api } from '@/utils/api'

const { t } = useI18n()
const auth = useAuthStore()

const steps = ref(null)
const loading = ref(true)
const dismissed = ref(false)

const completedCount = computed(() => steps.value?.filter(s => s.completed).length || 0)
const totalCount = computed(() => steps.value?.length || 6)
const progress = computed(() => Math.round((completedCount.value / totalCount.value) * 100))
const allCompleted = computed(() => steps.value && completedCount.value === totalCount.value)

const stepMeta = {
  account_created: { icon: 'tabler-user-check', label: 'onboarding.steps.accountCreated.label', to: null },
  company_profile: { icon: 'tabler-building', label: 'onboarding.steps.companyProfile.label', to: '/company/profile/overview' },
  payment_method: { icon: 'tabler-wallet', label: 'onboarding.steps.paymentMethod.label', to: '/company/billing?tab=payment-methods' },
  invite_member: { icon: 'tabler-users-plus', label: 'onboarding.steps.inviteMember.label', to: '/company/members' },
  activate_module: { icon: 'tabler-puzzle', label: 'onboarding.steps.activateModule.label', to: '/company/modules' },
  first_document: { icon: 'tabler-file-upload', label: 'onboarding.steps.firstDocument.label', to: { name: 'company-documents-tab', params: { tab: 'overview' } } },
}

// Show full widget when not dismissed and not all completed
const showFull = computed(() => auth.isOwner && !loading.value && !dismissed.value && !allCompleted.value)

// Show reopen banner when dismissed but not all completed
const showReopenBanner = computed(() => auth.isOwner && !loading.value && dismissed.value && !allCompleted.value)

onMounted(async () => {
  if (!auth.isOwner) {
    loading.value = false

    return
  }

  try {
    const data = await $api('/dashboard/onboarding')

    if (data?.dismissed) {
      dismissed.value = true

      // Store steps even when dismissed (API now returns them)
      steps.value = data?.steps || []
    }
    else {
      steps.value = data?.steps || []
    }
  }
  catch {
    steps.value = []
  }
  finally {
    loading.value = false
  }
})

const dismissing = ref(false)

const dismiss = async () => {
  dismissing.value = true
  try {
    await $api('/dashboard/onboarding/dismiss', { method: 'POST' })
    dismissed.value = true
  }
  finally {
    dismissing.value = false
  }
}

const reopening = ref(false)

const reopen = async () => {
  reopening.value = true
  try {
    const data = await $api('/dashboard/onboarding/reopen', { method: 'POST' })

    steps.value = data?.steps || []
    dismissed.value = false
  }
  finally {
    reopening.value = false
  }
}
</script>

<template>
  <!-- Reopen banner — shown when dismissed but not all steps completed -->
  <VAlert
    v-if="showReopenBanner"
    type="info"
    variant="tonal"
    density="compact"
    class="mb-6"
    closable
  >
    <template #text>
      <div class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span class="text-body-2">
          {{ t('onboarding.resumeHint') }}
        </span>
        <VBtn
          size="small"
          variant="tonal"
          color="info"
          prepend-icon="tabler-refresh"
          :loading="reopening"
          @click="reopen"
        >
          {{ t('onboarding.resumeSetup') }}
        </VBtn>
      </div>
    </template>
  </VAlert>

  <!-- Full onboarding widget -->
  <VCard
    v-if="showFull"
    class="mb-6 position-relative"
  >
    <!-- Dismiss button — top right of card -->
    <VBtn
      icon
      variant="text"
      size="x-small"
      :loading="dismissing"
      class="dismiss-btn"
      @click="dismiss"
    >
      <VIcon
        icon="tabler-x"
        size="18"
      />
      <VTooltip activator="parent">
        {{ t('onboarding.dismiss') }}
      </VTooltip>
    </VBtn>

    <VRow no-gutters>
      <VCol cols="8">
        <VCardText>
          <h5 class="text-h5 mb-1">
            {{ t('onboarding.welcome', { name: auth.user?.first_name || auth.user?.display_name }) }}
          </h5>

          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('onboarding.setupPrompt') }}
          </p>

          <div class="d-flex align-center gap-2 mb-3">
            <VProgressLinear
              :model-value="progress"
              color="primary"
              rounded
              height="6"
            />
            <VChip
              color="primary"
              size="x-small"
              variant="tonal"
            >
              {{ completedCount }}/{{ totalCount }}
            </VChip>
          </div>

          <VList
            density="compact"
            class="pa-0"
          >
            <VListItem
              v-for="step in steps"
              :key="step.key"
              :to="!step.completed && stepMeta[step.key]?.to ? stepMeta[step.key].to : undefined"
              :class="step.completed ? 'text-medium-emphasis' : ''"
              density="compact"
              class="px-0"
            >
              <template #prepend>
                <VIcon
                  :icon="step.completed ? 'tabler-circle-check-filled' : (stepMeta[step.key]?.icon || 'tabler-circle')"
                  :color="step.completed ? 'success' : 'default'"
                  size="18"
                  class="me-2"
                />
              </template>
              <VListItemTitle
                class="text-body-2"
                :class="step.completed ? 'text-decoration-line-through' : 'font-weight-medium'"
              >
                {{ t(stepMeta[step.key]?.label || step.key) }}
              </VListItemTitle>
              <template
                v-if="!step.completed && stepMeta[step.key]?.to"
                #append
              >
                <VIcon
                  icon="tabler-chevron-right"
                  size="14"
                />
              </template>
            </VListItem>
          </VList>
        </VCardText>
      </VCol>

      <VCol cols="4">
        <VCardText class="pb-0 px-0 position-relative h-100">
          <VImg
            :src="congoImg"
            :height="$vuetify.display.smAndUp ? 147 : 125"
            class="congo-john-img w-100"
          />
        </VCardText>
      </VCol>
    </VRow>
  </VCard>
</template>

<style lang="scss" scoped>
.congo-john-img {
  position: absolute;
  inset-block-end: 0;
  inset-inline-end: 1.25rem;
}

.dismiss-btn {
  position: absolute;
  inset-block-start: 8px;
  inset-inline-end: 8px;
  z-index: 1;
}
</style>
