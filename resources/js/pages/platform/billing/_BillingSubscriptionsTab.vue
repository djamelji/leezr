<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)
const statusFilter = ref('')

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.planKey'), key: 'plan_key' },
  { title: t('platformBilling.interval'), key: 'interval' },
  { title: t('platformBilling.status'), key: 'status', width: '130px' },
  { title: t('platformBilling.currentPeriodEnd'), key: 'current_period_end' },
  { title: t('platformBilling.cancelAtPeriodEnd'), key: 'cancel_at_period_end', align: 'center', width: '120px' },
])

const statusOptions = computed(() => [
  { title: t('platformBilling.filterAll'), value: '' },
  { title: t('platformBilling.statusActive'), value: 'active' },
  { title: t('platformBilling.statusTrialing'), value: 'trialing' },
  { title: t('platformBilling.statusPastDue'), value: 'past_due' },
  { title: t('platformBilling.statusCancelled'), value: 'cancelled' },
  { title: t('platformBilling.statusSuspended'), value: 'suspended' },
])

const statusColor = status => {
  const colors = {
    active: 'success',
    trialing: 'info',
    past_due: 'error',
    cancelled: 'warning',
    suspended: 'secondary',
  }

  return colors[status] || 'secondary'
}

const statusLabel = status => {
  const map = {
    active: 'statusActive',
    trialing: 'statusTrialing',
    past_due: 'statusPastDue',
    cancelled: 'statusCancelled',
    suspended: 'statusSuspended',
  }

  return t(`platformBilling.${map[status] || status}`)
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchAllSubscriptions({
      page,
      status: statusFilter.value || undefined,
    })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => load())
watch(statusFilter, () => load(1))
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center">
      <VIcon
        icon="tabler-receipt"
        class="me-2"
      />
      {{ t('platformBilling.tabs.subscriptions') }}
      <VSpacer />
      <AppSelect
        v-model="statusFilter"
        :items="statusOptions"
        density="compact"
        style="max-inline-size: 160px;"
      />
    </VCardTitle>

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && store.allSubscriptions.length === 0"
        type="table"
      />

      <div
        v-else-if="store.allSubscriptions.length === 0 && !isLoading"
        class="text-center pa-6 text-disabled"
      >
        <VIcon
          icon="tabler-receipt-off"
          size="48"
          class="mb-2"
        />
        <p class="text-body-1">
          {{ t('platformBilling.noSubscriptions') }}
        </p>
      </div>

      <VDataTable
        v-else
        :headers="headers"
        :items="store.allSubscriptions"
        :loading="isLoading"
        :items-per-page="store.allSubscriptionsPagination.per_page"
        hide-default-footer
      >
        <template #item.company="{ item }">
          <span class="font-weight-medium">
            {{ item.company?.name || '—' }}
          </span>
        </template>

        <template #item.plan_key="{ item }">
          <VChip
            size="small"
            variant="tonal"
            color="primary"
          >
            {{ item.plan_key }}
          </VChip>
        </template>

        <template #item.interval="{ item }">
          {{ item.interval || '—' }}
        </template>

        <template #item.status="{ item }">
          <VChip
            :color="statusColor(item.status)"
            size="small"
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>

        <template #item.current_period_end="{ item }">
          {{ formatDate(item.current_period_end) }}
        </template>

        <template #item.cancel_at_period_end="{ item }">
          <VChip
            v-if="item.cancel_at_period_end"
            size="small"
            color="warning"
          >
            {{ t('platformBilling.yes') }}
          </VChip>
          <span
            v-else
            class="text-disabled"
          >{{ t('platformBilling.no') }}</span>
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('platformBilling.subscriptionCount', { count: store.allSubscriptionsPagination.total }) }}
            </span>
            <VPagination
              v-if="store.allSubscriptionsPagination.last_page > 1"
              :model-value="store.allSubscriptionsPagination.current_page"
              :length="store.allSubscriptionsPagination.last_page"
              :total-visible="5"
              @update:model-value="load"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>
</template>
