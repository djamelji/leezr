<script setup>
import { useDeliveryStore } from '@/modules/logistics-shipments/stores/delivery.store'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations' } })

const deliveryStore = useDeliveryStore()
const router = useRouter()

const isLoading = ref(true)
const statusFilter = ref('')

const headers = [
  { title: 'Reference', key: 'reference' },
  { title: 'Status', key: 'status', width: '140px' },
  { title: 'Destination', key: 'destination_address', sortable: false },
  { title: 'Scheduled', key: 'scheduled_at' },
  { title: 'Actions', key: 'actions', align: 'center', width: '80px', sortable: false },
]

const statusOptions = [
  { title: 'All', value: '' },
  { title: 'Planned', value: 'planned' },
  { title: 'In Transit', value: 'in_transit' },
  { title: 'Delivered', value: 'delivered' },
  { title: 'Canceled', value: 'canceled' },
]

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
    draft: 'Draft',
    planned: 'Planned',
    in_transit: 'In Transit',
    delivered: 'Delivered',
    canceled: 'Canceled',
  }

  return labels[status] || status
}

const formatDate = dateStr => {
  if (!dateStr)
    return '—'

  return new Date(dateStr).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
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
          <span>My Deliveries</span>
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
              placeholder="Filter by status"
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
            No deliveries assigned to you.
          </div>
        </template>

        <!-- Pagination -->
        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ deliveryStore.pagination.total }} delivery(ies)
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
