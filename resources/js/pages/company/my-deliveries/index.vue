<script setup>
import { formatDate } from '@/utils/datetime'
import { useDeliveryStore } from '@/modules/logistics-shipments/stores/delivery.store'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations', permission: 'shipments.view_own' } })

const { t } = useI18n()
const deliveryStore = useDeliveryStore()
const router = useRouter()

const isLoading = ref(true)
const statusFilter = ref('')

const headers = computed(() => [
  { title: t('deliveries.reference'), key: 'reference' },
  { title: t('common.status'), key: 'status', width: '140px' },
  { title: t('deliveries.destination'), key: 'destination_address', sortable: false },
  { title: t('deliveries.scheduled'), key: 'scheduled_at' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '80px', sortable: false },
])

const statusOptions = computed(() => [
  { title: t('deliveries.all'), value: '' },
  { title: t('deliveries.statusPlanned'), value: 'planned' },
  { title: t('deliveries.statusInTransit'), value: 'in_transit' },
  { title: t('deliveries.statusDelivered'), value: 'delivered' },
  { title: t('deliveries.statusCanceled'), value: 'canceled' },
])

const statusColor = status => {
  const colors = {
    draft: 'secondary',
    planned: 'info',
    in_transit: 'warning',
    delivered: 'success',
    canceled: 'error',
  }

  return colors[status] || 'secondary'
}

const statusLabel = status => {
  const labels = {
    draft: t('deliveries.statusDraft'),
    planned: t('deliveries.statusPlanned'),
    in_transit: t('deliveries.statusInTransit'),
    delivered: t('deliveries.statusDelivered'),
    canceled: t('deliveries.statusCanceled'),
  }

  return labels[status] || status
}

const truncate = (str, len = 40) => {
  if (!str)
    return '—'

  return str.length > len ? `${str.substring(0, len)}...` : str
}

const loadDeliveries = async (page = 1) => {
  isLoading.value = true
  try {
    await deliveryStore.fetchDeliveries({
      page,
      status: statusFilter.value || undefined,
    })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => loadDeliveries())

watch(statusFilter, () => loadDeliveries(1))

const onPageChange = page => {
  loadDeliveries(page)
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-4">
        <div class="d-flex align-center gap-x-2">
          <VIcon icon="tabler-truck-delivery" />
          <span>{{ t('deliveries.title') }}</span>
        </div>
      </VCardTitle>

      <VCardText>
        <VRow class="mb-4">
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="t('deliveries.filterByStatus')"
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDataTable
        :headers="headers"
        :items="deliveryStore.deliveries"
        :loading="isLoading"
        :items-per-page="deliveryStore.pagination.per_page"
        hide-default-footer
      >
        <!-- Reference -->
        <template #item.reference="{ item }">
          <RouterLink
            :to="{ name: 'company-my-deliveries-id', params: { id: item.id } }"
            class="text-primary font-weight-medium"
          >
            {{ item.reference }}
          </RouterLink>
        </template>

        <!-- Status badge -->
        <template #item.status="{ item }">
          <VChip
            :color="statusColor(item.status)"
            size="small"
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>

        <!-- Destination -->
        <template #item.destination_address="{ item }">
          {{ truncate(item.destination_address) }}
        </template>

        <!-- Scheduled at -->
        <template #item.scheduled_at="{ item }">
          {{ formatDate(item.scheduled_at) }}
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <VBtn
            icon
            size="small"
            variant="text"
            :to="{ name: 'company-my-deliveries-id', params: { id: item.id } }"
          >
            <VIcon icon="tabler-eye" />
          </VBtn>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            {{ t('deliveries.noDeliveries') }}
          </div>
        </template>

        <!-- Pagination -->
        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('deliveries.deliveryCount', { count: deliveryStore.pagination.total }) }}
            </span>
            <VPagination
              v-if="deliveryStore.pagination.last_page > 1"
              :model-value="deliveryStore.pagination.current_page"
              :length="deliveryStore.pagination.last_page"
              :total-visible="5"
              @update:model-value="onPageChange"
            />
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
