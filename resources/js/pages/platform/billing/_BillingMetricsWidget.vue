<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

onMounted(() => store.fetchMetrics())

const cards = computed(() => {
  const m = store.metrics

  if (!m) return []

  return [
    {
      title: 'MRR',
      value: formatMoney(m.mrr),
      icon: 'tabler-trending-up',
      color: 'primary',
    },
    {
      title: 'ARR',
      value: formatMoney(m.arr),
      icon: 'tabler-chart-bar',
      color: 'success',
    },
    {
      title: t('platformBilling.metrics.activeSubscriptions'),
      value: m.active_subscriptions,
      icon: 'tabler-receipt',
      color: 'info',
    },
    {
      title: t('platformBilling.metrics.trialing'),
      value: m.trialing_subscriptions,
      icon: 'tabler-clock',
      color: 'warning',
    },
    {
      title: t('platformBilling.metrics.addonRevenue'),
      value: formatMoney(m.addon_mrr),
      icon: 'tabler-puzzle',
      color: 'secondary',
    },
    {
      title: t('platformBilling.metrics.churn'),
      value: `${(m.churn_rate * 100).toFixed(1)}%`,
      icon: 'tabler-arrow-down-right',
      color: m.churn_rate > 0.05 ? 'error' : 'success',
    },
  ]
})
</script>

<template>
  <VCard class="mb-6">
    <VCardTitle>
      <VIcon
        icon="tabler-report-money"
        class="me-2"
      />
      {{ t('platformBilling.metrics.title') }}
    </VCardTitle>
    <VCardText>
      <VSkeletonLoader
        v-if="store.metricsLoading"
        type="card"
      />

      <VRow v-else-if="store.metrics">
        <VCol
          v-for="card in cards"
          :key="card.title"
          cols="6"
          md="4"
          lg="2"
        >
          <VCard
            flat
            border
            class="text-center pa-4"
          >
            <VAvatar
              size="42"
              variant="tonal"
              :color="card.color"
              class="mb-2"
            >
              <VIcon :icon="card.icon" />
            </VAvatar>
            <h4 class="text-h5 font-weight-bold">
              {{ card.value }}
            </h4>
            <span class="text-body-2 text-disabled">{{ card.title }}</span>
          </VCard>
        </VCol>
      </VRow>

      <div
        v-else
        class="text-center pa-4 text-disabled"
      >
        {{ t('platformBilling.metrics.noData') }}
      </div>
    </VCardText>
  </VCard>
</template>
