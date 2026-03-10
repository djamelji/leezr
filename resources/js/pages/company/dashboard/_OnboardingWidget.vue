<script setup>
import congoImg from '@images/illustrations/congo-illustration.png'
import { useAuthStore } from '@/core/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()

const steps = ref(null)
const loading = ref(true)

const completedCount = computed(() => steps.value?.filter(s => s.completed).length || 0)
const totalCount = computed(() => steps.value?.length || 5)
const progress = computed(() => Math.round((completedCount.value / totalCount.value) * 100))
const allCompleted = computed(() => completedCount.value === totalCount.value)

const stepMeta = {
  account_created: { icon: 'tabler-user-check', label: 'onboarding.accountCreated', to: null },
  plan_selected: { icon: 'tabler-credit-card', label: 'onboarding.planSelected', to: '/company/plan' },
  company_profile: { icon: 'tabler-building', label: 'onboarding.companyProfile', to: '/company/settings' },
  payment_method: { icon: 'tabler-wallet', label: 'onboarding.paymentMethod', to: '/company/billing?tab=payment-methods' },
  invite_member: { icon: 'tabler-users-plus', label: 'onboarding.inviteMember', to: '/company/members' },
}

onMounted(async () => {
  try {
    const { data } = await useApi('/company/dashboard/onboarding')
    steps.value = data.value?.steps || []
  }
  catch {
    steps.value = []
  }
  finally {
    loading.value = false
  }
})
</script>

<template>
  <!-- Welcome card (Vuexy EcommerceCongratulationsJohn pattern) -->
  <VCard class="mb-6">
    <VRow no-gutters>
      <VCol cols="8">
        <VCardText>
          <h5 class="text-h5 text-no-wrap mb-1">
            {{ t('onboarding.welcome', { name: auth.user?.first_name || auth.user?.display_name }) }}
          </h5>
          <p class="text-body-1 mb-0">
            {{ allCompleted
              ? t('onboarding.allDone')
              : t('onboarding.setupPrompt')
            }}
          </p>
        </VCardText>
      </VCol>

      <VCol cols="4">
        <VCardText class="pb-0 px-0 position-relative h-100">
          <VImg
            :src="congoImg"
            :height="$vuetify.display.smAndUp ? 147 : 125"
            class="congo-img w-100"
          />
        </VCardText>
      </VCol>
    </VRow>
  </VCard>

  <!-- Onboarding checklist (only if not all completed) -->
  <VCard
    v-if="!allCompleted && !loading"
    class="mb-6"
  >
    <VCardText>
      <div class="d-flex align-center justify-space-between mb-4">
        <h6 class="text-h6">
          {{ t('onboarding.gettingStarted') }}
        </h6>
        <VChip
          color="primary"
          size="small"
        >
          {{ completedCount }}/{{ totalCount }}
        </VChip>
      </div>

      <VProgressLinear
        :model-value="progress"
        color="primary"
        rounded
        height="8"
        class="mb-4"
      />

      <VList density="compact">
        <VListItem
          v-for="step in steps"
          :key="step.key"
          :to="!step.completed && stepMeta[step.key]?.to ? stepMeta[step.key].to : undefined"
          :class="step.completed ? 'text-medium-emphasis' : ''"
        >
          <template #prepend>
            <VIcon
              :icon="step.completed ? 'tabler-circle-check-filled' : (stepMeta[step.key]?.icon || 'tabler-circle')"
              :color="step.completed ? 'success' : 'default'"
              size="20"
              class="me-2"
            />
          </template>
          <VListItemTitle :class="step.completed ? 'text-decoration-line-through' : 'font-weight-medium'">
            {{ t(stepMeta[step.key]?.label || step.key) }}
          </VListItemTitle>
          <template
            v-if="!step.completed && stepMeta[step.key]?.to"
            #append
          >
            <VIcon
              icon="tabler-chevron-right"
              size="16"
            />
          </template>
        </VListItem>
      </VList>
    </VCardText>
  </VCard>
</template>

<style lang="scss" scoped>
.congo-img {
  position: absolute;
  inset-block-end: 0;
  inset-inline-end: 1.25rem;
}
</style>
