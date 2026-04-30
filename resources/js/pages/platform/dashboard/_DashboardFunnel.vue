<script setup>
import { $platformApi } from '@/utils/platformApi'

// Sub-component — no definePage — hub: platform/dashboard/[tab].vue

const { t } = useI18n()

const isLoading = ref(true)
const data = ref(null)
const days = ref(30)

const steps = ['started', 'company_info', 'admin_user', 'plan_selected', 'payment_info', 'completed']

const stepLabels = computed(() => ({
  started: t('onboardingFunnel.stepStarted'),
  company_info: t('onboardingFunnel.stepCompanyInfo'),
  admin_user: t('onboardingFunnel.stepAdminUser'),
  plan_selected: t('onboardingFunnel.stepPlanSelected'),
  payment_info: t('onboardingFunnel.stepPaymentInfo'),
  completed: t('onboardingFunnel.stepCompleted'),
}))

const funnelBarData = computed(() => {
  if (!data.value) return []

  return steps.map(step => ({
    step,
    label: stepLabels.value[step],
    count: data.value.steps[step] || 0,
    rate: data.value.conversion_rates[step] || 0,
  }))
})

const load = async () => {
  isLoading.value = true
  try {
    data.value = await $platformApi(`/onboarding/funnel?days=${days.value}`)
  }
  catch {
    // silent
  }
  finally {
    isLoading.value = false
  }
}

watch(days, load)
onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('onboardingFunnel.title') }}
        </h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('onboardingFunnel.subtitle') }}
        </p>
      </div>
      <div class="d-flex align-center gap-3">
        <AppSelect
          v-model="days"
          :items="[{ title: '7 jours', value: 7 }, { title: '30 jours', value: 30 }, { title: '90 jours', value: 90 }]"
          style="inline-size: 140px"
          density="compact"
        />
        <VBtn
          variant="tonal"
          size="small"
          :loading="isLoading"
          @click="load"
        >
          <VIcon
            start
            icon="tabler-refresh"
            size="18"
          />
          {{ t('common.refresh') }}
        </VBtn>
      </div>
    </div>

    <VSkeletonLoader
      v-if="isLoading && !data"
      type="card, card"
    />

    <template v-if="data">
      <!-- KPI Row -->
      <VRow class="card-grid card-grid-xs mb-6">
        <VCol
          cols="6"
          md="3"
        >
          <VCard>
            <VCardText class="text-center">
              <div class="text-h4">
                {{ data.steps.started || 0 }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('onboardingFunnel.started') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="6"
          md="3"
        >
          <VCard>
            <VCardText class="text-center">
              <div class="text-h4">
                {{ data.steps.completed || 0 }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('onboardingFunnel.completed') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="6"
          md="3"
        >
          <VCard>
            <VCardText class="text-center">
              <div class="text-h4 text-success">
                {{ data.overall_conversion }}%
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('onboardingFunnel.conversionRate') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="6"
          md="3"
        >
          <VCard>
            <VCardText class="text-center">
              <div class="text-h4 text-warning">
                {{ data.abandoned || 0 }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('onboardingFunnel.abandoned') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- Funnel visualization -->
      <VCard class="mb-6">
        <VCardTitle>{{ t('onboardingFunnel.funnelTitle') }}</VCardTitle>
        <VCardText>
          <div
            v-for="bar in funnelBarData"
            :key="bar.step"
            class="mb-3"
          >
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-body-2">{{ bar.label }}</span>
              <span class="text-body-2 font-weight-medium">{{ bar.count }} ({{ bar.rate }}%)</span>
            </div>
            <VProgressLinear
              :model-value="bar.rate"
              :color="bar.step === 'completed' ? 'success' : 'primary'"
              height="24"
              rounded
            />
          </div>
        </VCardText>
      </VCard>

      <!-- Abandonment analysis -->
      <VCard
        v-if="Object.keys(data.abandoned_at_step || {}).length"
        class="mb-6"
      >
        <VCardTitle>{{ t('onboardingFunnel.abandonmentTitle') }}</VCardTitle>
        <VCardText>
          <VList density="compact">
            <VListItem
              v-for="(count, step) in data.abandoned_at_step"
              :key="step"
            >
              <template #prepend>
                <span class="text-body-2">{{ stepLabels[step] || step }}</span>
              </template>
              <template #append>
                <VChip
                  color="warning"
                  size="small"
                >
                  {{ count }}
                </VChip>
              </template>
            </VListItem>
          </VList>
        </VCardText>
      </VCard>

      <!-- Daily trend -->
      <VCard v-if="data.daily_trend?.length">
        <VCardTitle>{{ t('onboardingFunnel.dailyTrend') }}</VCardTitle>
        <VCardText>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('common.date') }}</th>
                <th class="text-end">
                  {{ t('onboardingFunnel.started') }}
                </th>
                <th class="text-end">
                  {{ t('onboardingFunnel.completed') }}
                </th>
                <th class="text-end">
                  {{ t('onboardingFunnel.conversionRate') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="day in data.daily_trend"
                :key="day.date"
              >
                <td>{{ day.date }}</td>
                <td class="text-end">
                  {{ day.started }}
                </td>
                <td class="text-end">
                  {{ day.completed }}
                </td>
                <td class="text-end">
                  {{ day.started > 0 ? Math.round(day.completed / day.started * 100) : 0 }}%
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
