<script setup>
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import { shipmentStatusColor } from '@/utils/shipments'
import { formatDate } from '@/utils/datetime'
import { useAuthStore } from '@/core/stores/auth'
import { useShipmentStore } from '@/modules/logistics-shipments/stores/shipment.store'
import { useDisplay } from 'vuetify'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations', permission: 'shipments.view' } })

const { t } = useI18n()
const auth = useAuthStore()
const shipmentStore = useShipmentStore()
const router = useRouter()
const { smAndDown } = useDisplay() // ADR-862 (A1) : a phone gets a list of shipments, not a squeezed 5-column table

const isLoading = ref(true)
const statusFilter = ref(typeof useRoute().query.status === 'string' ? useRoute().query.status : '') // ADR-776 : lien filtré depuis l'accueil
const searchQuery = ref('')

const canManage = computed(() => auth.hasPermission('shipments.create'))
const canViewOwnDeliveries = computed(() => auth.hasPermission('shipments.view_own') || auth.isOwner)

const headers = computed(() => [
  { title: t('shipments.reference'), key: 'reference' },
  { title: t('common.status'), key: 'status', width: '130px' },
  { title: t('shipments.route'), key: 'route', sortable: false },
  { title: t('shipments.assignedTo'), key: 'assigned_to', sortable: false },
  { title: '', key: 'actions', align: 'end', width: '56px', sortable: false },
])

const statusOptions = computed(() => [
  { title: t('shipments.all'), value: '' },
  { title: t('shipments.statusDraft'), value: 'draft' },
  { title: t('shipments.statusPlanned'), value: 'planned' },
  { title: t('shipments.statusInTransit'), value: 'in_transit' },
  { title: t('shipments.statusDelivered'), value: 'delivered' },
  { title: t('shipments.statusCanceled'), value: 'canceled' },
])


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

const initials = name => (name || '?').split(' ').map(x => x[0]).join('').slice(0, 2).toUpperCase()

const onPageChange = page => {
  loadShipments(page)
}
</script>

<template>
  <div>
    <!-- ADR-821 — liste d'exploitation : en-tête de page, filtres d'état en un clic, trajet lisible -->
    <AppPageHeader
      :eyebrow="t('l7.family.logistics')"
      :title="t('shipments.title')"
      :description="t('shipments.listSubtitle')"
    >
      <template #actions>
        <VBtn
          v-if="canViewOwnDeliveries"
          variant="tonal"
          color="secondary"
          prepend-icon="tabler-truck-delivery"
          :to="{ name: 'company-my-deliveries' }"
        >
          {{ t('shipments.myDeliveries') }}
        </VBtn>
        <VBtn
          v-if="canManage"
          v-can="'shipments.create'"
          prepend-icon="tabler-plus"
          :to="{ name: 'company-shipments-create' }"
        >
          {{ t('shipments.newShipment') }}
        </VBtn>
      </template>
    </AppPageHeader>

    <VCard>
      <VCardText class="d-flex flex-wrap align-center gap-4">
        <VChipGroup
          v-model="statusFilter"
          selected-class="text-primary"
          mandatory
          :column="smAndDown"
          data-test="shipments-status-filter"
        >
          <VChip
            v-for="o in statusOptions"
            :key="o.value"
            :value="o.value"
            variant="tonal"
            label
          >
            {{ o.title }}
          </VChip>
        </VChipGroup>
        <VSpacer />
        <AppTextField
          v-model="searchQuery"
          :placeholder="t('shipments.searchByReference')"
          prepend-inner-icon="tabler-search"
          clearable
          :style="smAndDown ? 'inline-size: 100%' : 'max-inline-size: 280px'"
        />
      </VCardText>
      <VDivider />

      <!-- ADR-862 (A1) — phone : one readable card per shipment (reference, status, whole route, driver), same link -->
      <template v-if="smAndDown">
        <VProgressLinear
          v-if="isLoading"
          indeterminate
          color="primary"
        />
        <VList
          v-if="shipmentStore.shipments.length"
          class="py-0"
          data-test="shipments-list-mobile"
        >
          <template
            v-for="(item, i) in shipmentStore.shipments"
            :key="item.id"
          >
            <VDivider v-if="i" />
            <VListItem
              :to="{ name: 'company-shipments-id', params: { id: item.id } }"
              class="py-3"
              :data-test="`shipment-card-${item.id}`"
            >
              <div class="d-flex align-center justify-space-between gap-2">
                <span class="text-primary font-weight-medium">{{ item.reference }}</span>
                <VChip
                  :color="shipmentStatusColor(item.status)"
                  size="small"
                  label
                >
                  {{ statusLabel(item.status) }}
                </VChip>
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ item.scheduled_at ? formatDate(item.scheduled_at) : t('shipments.notScheduled') }}
              </div>
              <div class="d-flex align-start gap-2 mt-2 text-body-2">
                <VIcon
                  icon="tabler-route"
                  size="18"
                  class="text-medium-emphasis mt-1 flex-shrink-0"
                />
                <div style="min-inline-size: 0">
                  <div>{{ item.origin_address || '—' }}</div>
                  <div class="text-medium-emphasis">
                    → {{ item.destination_address || '—' }}
                  </div>
                </div>
              </div>
              <div class="d-flex align-center gap-2 mt-2">
                <template v-if="item.assigned_to">
                  <VAvatar
                    size="24"
                    color="primary"
                    variant="tonal"
                  >
                    <span class="text-caption">{{ initials(item.assigned_to.display_name) }}</span>
                  </VAvatar>
                  <span class="text-body-2">{{ item.assigned_to.display_name }}</span>
                </template>
                <VChip
                  v-else
                  size="small"
                  label
                  variant="outlined"
                  color="warning"
                >
                  {{ t('shipments.unassigned') }}
                </VChip>
              </div>
              <template #append>
                <VIcon
                  icon="tabler-chevron-right"
                  class="text-medium-emphasis"
                />
              </template>
            </VListItem>
          </template>
        </VList>
        <div
          v-else-if="!isLoading"
          class="text-center py-10 text-medium-emphasis"
        >
          <VIcon
            icon="tabler-truck-off"
            size="36"
            class="mb-2"
          />
          <div>{{ t('shipments.noShipmentsFound') }}</div>
        </div>
        <VDivider />
        <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
          <span class="text-body-2 text-medium-emphasis">
            {{ t('shipments.shipmentCount', { count: shipmentStore.pagination.total }) }}
          </span>
          <VPagination
            v-if="shipmentStore.pagination.last_page > 1"
            :model-value="shipmentStore.pagination.current_page"
            :length="shipmentStore.pagination.last_page"
            :total-visible="3"
            density="compact"
            @update:model-value="onPageChange"
          />
        </div>
      </template>

      <VDataTable
        v-else
        :headers="headers"
        :items="shipmentStore.shipments"
        :loading="isLoading"
        :items-per-page="shipmentStore.pagination.per_page"
        hide-default-footer
        hover
        data-test="shipments-table"
        @click:row="(_e, { item }) => router.push({ name: 'company-shipments-id', params: { id: item.id } })"
      >
        <template #item.reference="{ item }">
          <div class="py-2">
            <RouterLink
              :to="{ name: 'company-shipments-id', params: { id: item.id } }"
              class="text-primary font-weight-medium text-no-wrap"
            >
              {{ item.reference }}
            </RouterLink>
            <div class="text-caption text-medium-emphasis">
              {{ item.scheduled_at ? formatDate(item.scheduled_at) : t('shipments.notScheduled') }}
            </div>
          </div>
        </template>

        <template #item.status="{ item }">
          <VChip
            :color="shipmentStatusColor(item.status)"
            size="small"
            label
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>

        <template #item.route="{ item }">
          <div class="d-flex align-start gap-2 py-2">
            <VIcon
              icon="tabler-route"
              size="18"
              class="text-medium-emphasis mt-1"
            />
            <div class="text-body-2">
              <div>{{ truncate(item.origin_address, 42) }}</div>
              <div class="text-medium-emphasis">
                → {{ truncate(item.destination_address, 42) }}
              </div>
            </div>
          </div>
        </template>

        <template #item.assigned_to="{ item }">
          <div
            v-if="item.assigned_to"
            class="d-flex align-center gap-2"
          >
            <VAvatar
              size="30"
              color="primary"
              variant="tonal"
            >
              <span class="text-caption">{{ initials(item.assigned_to.display_name) }}</span>
            </VAvatar>
            <div>
              <div class="text-body-2">
                {{ item.assigned_to.display_name }}
              </div>
              <div
                v-if="item.driver_identifier"
                class="text-caption text-medium-emphasis"
              >
                {{ item.driver_identifier }}
              </div>
            </div>
          </div>
          <VChip
            v-else
            size="small"
            label
            variant="outlined"
            color="warning"
          >
            {{ t('shipments.unassigned') }}
          </VChip>
        </template>

        <template #item.actions="{ item }">
          <IconBtn
            :to="{ name: 'company-shipments-id', params: { id: item.id } }"
            :aria-label="item.reference"
          >
            <VIcon icon="tabler-chevron-right" />
          </IconBtn>
        </template>

        <template #no-data>
          <div class="text-center py-10 text-medium-emphasis">
            <VIcon
              icon="tabler-truck-off"
              size="36"
              class="mb-2"
            />
            <div>{{ t('shipments.noShipmentsFound') }}</div>
          </div>
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-medium-emphasis">
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
