<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { usePublicPlans } from '@/composables/usePublicPlans'

const { t } = useI18n()
const auth = useAuthStore()
const { plans, fetchPlans } = usePublicPlans()

onMounted(() => {
  fetchPlans()
})

const planKey = computed(() => auth.currentCompany?.plan_key ?? 'starter')

const planName = computed(() => {
  const fromApi = plans.value.find(p => p.key === planKey.value)
  if (fromApi) return fromApi.name

  // Fallback to hardcoded mapping
  const names = { starter: 'Starter', pro: 'Pro', business: 'Business' }

  return names[planKey.value] || planKey.value
})

const planColor = computed(() => {
  const colors = { starter: 'secondary', pro: 'primary', business: 'warning' }

  return colors[planKey.value] || 'primary'
})

const showUpgrade = computed(() => planKey.value !== 'business')
</script>

<template>
  <VCard>
    <VCardText class="d-flex align-center justify-space-between">
      <div class="d-flex align-center gap-3">
        <VIcon
          icon="tabler-credit-card"
          size="24"
          :color="planColor"
        />
        <div>
          <span class="text-body-1 font-weight-medium">{{ t('dashboard.currentPlan') }}</span>
          <VChip
            :color="planColor"
            label
            size="small"
            class="ms-2"
          >
            {{ planName }}
          </VChip>
        </div>
      </div>

      <VBtn
        v-if="showUpgrade"
        size="small"
        variant="tonal"
        color="primary"
        :to="{ name: 'company-plan' }"
      >
        {{ t('dashboard.upgrade') }}
      </VBtn>
    </VCardText>
  </VCard>
</template>
