<script setup>
import { formatDate } from '@/utils/datetime'
import { useAuthStore } from '@/core/stores/auth'
import { useShipmentStore } from '@/modules/logistics-shipments/stores/shipment.store'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations' } })

const { t } = useI18n()
const auth = useAuthStore()
const shipmentStore = useShipmentStore()
const router = useRouter()

const isLoading = ref(true)
const statusFilter = ref('')
const searchQuery = ref('')

const canManage = computed(() => auth.hasPermission('shipments.create'))
const canViewOwnDeliveries = computed(() => auth.hasPermission('shipments.view_own') || auth.isOwner)

const headers = computed(() => [
  { title: t('shipments.reference'), key: 'reference' },
  { title: t('common.status'), key: 'status', width: '140px' },
  { title: t('shipments.assignedTo'), key: 'assigned_to', sortable: false },
  { title: t('shipments.origin'), key: 'origin_address', sortable: false },
  { title: t('shipments.destination'), key: 'destination_address', sortable: false },
  { title: t('shipments.scheduled'), key: 'scheduled_at' },
  { title: t('shipments.createdBy'), key: 'created_by', sortable: false },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '80px', sortable: false },
])

const statusOptions = computed(() => [
  { title: t('shipments.all'), value: '' },
  { title: t('shipments.statusDraft'), value: 'draft' },
  { title: t('shipments.statusPlanned'), value: 'planned' },
  { title: t('shipments.statusInTransit'), value: 'in_transit' },
  { title: t('shipments.statusDelivered'), value: 'delivered' },
  { title: t('shipments.statusCanceled'), value: 'canceled' },
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
    draft: t('shipments.statusDraft'),
    planned: t('shipments.statusPlanned'),
    in_transit: t('shipments.statusInTransit'),
    delivered: t('shipments.statusDelivered'),
    canceled: t('shipments.statusCanceled'),
  }

  return labels[status] || status
}

const truncate = (str, len = 40) => {
  if (!str)
    return '—'

  return str.length > len ? `${str.substring(0, len)}...` : str
}

const loadShipments = async (page = 1) => {
  isLoading.value = true
  try {
    await shipmentStore.fetchShipments({
      page,
      status: statusFilter.value || undefined,
      search: searchQuery.value || undefined,
    })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => loadShipments())

watch([statusFilter, searchQuery], () => loadShipments(1))

const onPageChange = page => {
  loadShipments(page)
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-4">
        <div class="d-flex align-center gap-x-2">
          <VIcon icon="tabler-truck" />
          <span>{{ t('shipments.title') }}</span>
        </div>
        <div class="d-flex gap-2">
          <VBtn
            v-if="canViewOwnDeliveries"
            variant="tonal"
            color="info"
            prepend-icon="tabler-truck-delivery"
            :to="{ name: 'company-my-deliveries' }"
          >
            {{ t('shipments.myDeliveries') }}
          </VBtn>
          <VBtn
            v-if="canManage"
            prepend-icon="tabler-plus"
            :to="{ name: 'company-shipments-create' }"
          >
            {{ t('shipments.newShipment') }}
          </VBtn>
        </div>
      </VCardTitle>

      <VCardText>
        <VRow class="mb-4">
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model="searchQuery"
              :placeholder="t('shipments.searchByReference')"
              prepend-inner-icon="tabler-search"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="t('shipments.filterByStatus')"
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDataTable
        :headers="headers"
        :items="shipmentStore.shipments"
        :loading="isLoading"
        :items-per-page="shipmentStore.pagination.per_page"
        hide-default-footer
      >
        <!-- Reference -->
        <template #item.reference="{ item }">
          <RouterLink
            :to="{ name: 'company-shipments-id', params: { id: item.id } }"
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

        <!-- Assigned To -->
        <template #item.assigned_to="{ item }">
          <span v-if="item.assigned_to">
            {{ item.assigned_to.display_name }}
          </span>
          <span
            v-else
            class="text-disabled"
          >—</span>
        </template>

        <!-- Origin -->
        <template #item.origin_address="{ item }">
          {{ truncate(item.origin_address) }}
        </template>

        <!-- Destination -->
        <template #item.destination_address="{ item }">
          {{ truncate(item.destination_address) }}
        </template>

        <!-- Scheduled at -->
        <template #item.scheduled_at="{ item }">
          {{ formatDate(item.scheduled_at) }}
        </template>

        <!-- Created by -->
        <template #item.created_by="{ item }">
          <template v-if="item.created_by">
            {{ item.created_by.display_name }}
          </template>
          <span
            v-else
            class="text-disabled"
          >—</span>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <VBtn
            icon
            size="small"
            variant="text"
            :to="{ name: 'company-shipments-id', params: { id: item.id } }"
          >
            <VIcon icon="tabler-eye" />
          </VBtn>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            {{ t('shipments.noShipmentsFound') }}
          </div>
        </template>

        <!-- Pagination -->
        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('shipments.shipmentCount', { count: shipmentStore.pagination.total }) }}
            </span>
            <VPagination
              v-if="shipmentStore.pagination.last_page > 1"
              :model-value="shipmentStore.pagination.current_page"
              :length="shipmentStore.pagination.last_page"
              :total-visible="5"
              @update:model-value="onPageChange"
            />
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
