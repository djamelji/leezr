<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)
const statusFilter = ref('')

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.planKey'), key: 'plan_key' },
  { title: t('platformBilling.interval'), key: 'interval' },
  { title: t('platformBilling.status'), key: 'status', width: '130px' },
  { title: t('platformBilling.estimatedNext'), key: 'estimated_next_amount', align: 'end', width: '140px' },
  { title: t('platformBilling.currentPeriodEnd'), key: 'current_period_end' },
  { title: t('platformBilling.cancelAtPeriodEnd'), key: 'cancel_at_period_end', align: 'center', width: '120px' },
  { title: t('platformBilling.actions'), key: 'actions', sortable: false, align: 'center', width: '160px' },
])

const statusOptions = computed(() => [
  { title: t('platformBilling.filterAll'), value: '' },
  { title: t('platformBilling.statusPending'), value: 'pending' },
  { title: t('platformBilling.statusActive'), value: 'active' },
  { title: t('platformBilling.statusTrialing'), value: 'trialing' },
  { title: t('platformBilling.statusPastDue'), value: 'past_due' },
  { title: t('platformBilling.statusCancelled'), value: 'cancelled' },
  { title: t('platformBilling.statusSuspended'), value: 'suspended' },
])

const statusColor = status => {
  const colors = {
    pending: 'info',
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
    pending: 'statusPending',
    active: 'statusActive',
    trialing: 'statusTrialing',
    past_due: 'statusPastDue',
    cancelled: 'statusCancelled',
    suspended: 'statusSuspended',
  }

  return t(`platformBilling.${map[status] || status}`)
}

const actionLoading = ref({})

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

const { toast } = useAppToast()

const handleApprove = async item => {
  actionLoading.value = { ...actionLoading.value, [item.id]: 'approve' }
  try {
    await store.approveSubscription(item.id)
    toast(t('platformBilling.toasts.approveSuccess'), 'success')
    await load(store.allSubscriptionsPagination.current_page)
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    delete actionLoading.value[item.id]
  }
}

const handleReject = async item => {
  actionLoading.value = { ...actionLoading.value, [item.id]: 'reject' }
  try {
    await store.rejectSubscription(item.id)
    toast(t('platformBilling.toasts.rejectSuccess'), 'success')
    await load(store.allSubscriptionsPagination.current_page)
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    delete actionLoading.value[item.id]
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
          <RouterLink
            v-if="item.company?.id"
            :to="{ path: `/platform/companies/${item.company.id}`, query: { tab: 'billing' } }"
            class="font-weight-medium text-high-emphasis text-decoration-none"
          >
            {{ item.company.name }}
          </RouterLink>
          <span v-else class="font-weight-medium">—</span>
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

        <template #item.estimated_next_amount="{ item }">
          <span class="font-weight-medium">
            {{ formatMoney(item.estimated_next_amount ?? 0) }}
          </span>
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

        <template #item.actions="{ item }">
          <div
            v-if="item.status === 'pending'"
            class="d-flex gap-1 justify-center"
          >
            <VBtn
              size="small"
              color="success"
              variant="tonal"
              :loading="actionLoading[item.id] === 'approve'"
              :disabled="!!actionLoading[item.id]"
              @click="handleApprove(item)"
            >
              {{ t('platformBilling.actionApprove') }}
            </VBtn>
            <VBtn
              size="small"
              color="error"
              variant="tonal"
              :loading="actionLoading[item.id] === 'reject'"
              :disabled="!!actionLoading[item.id]"
              @click="handleReject(item)"
            >
              {{ t('platformBilling.actionReject') }}
            </VBtn>
          </div>
          <span
            v-else
            class="text-disabled"
          >—</span>
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
