<script setup>
import { shipmentStatusColor } from '@/utils/shipments'
import { formatDate } from '@/utils/datetime'
import { useDeliveryStore } from '@/modules/logistics-shipments/stores/delivery.store'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations', permission: 'shipments.view_own' } })

const { t } = useI18n()
const deliveryStore = useDeliveryStore()
const route = useRoute()
const router = useRouter()

const isLoading = ref(true)
const isChangingStatus = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const delivery = computed(() => deliveryStore.currentDelivery)

const transitions = computed(() => ({
  planned: [
    { status: 'in_transit', label: t('deliveries.startTransit'), color: 'warning', icon: 'tabler-truck' },
  ],
  in_transit: [
    { status: 'delivered', label: t('deliveries.markDelivered'), color: 'success', icon: 'tabler-check' },
  ],
  delivered: [],
  canceled: [],
  draft: [],
}))

const availableTransitions = computed(() => {
  if (!delivery.value)
    return []

  return transitions.value[delivery.value.status] || []
})


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

const changeStatus = async newStatus => {
  isChangingStatus.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    await deliveryStore.updateStatus(delivery.value.id, newStatus)
    successMessage.value = t('deliveries.statusChangedTo', { status: statusLabel(newStatus) })
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('deliveries.failedToChangeStatus')
  }
  finally {
    isChangingStatus.value = false
  }
}

onMounted(async () => {
  try {
    await deliveryStore.fetchDelivery(route.params.id)
  }
  catch {
    await router.push({ name: 'company-my-deliveries' })
  }
  finally {
    isLoading.value = false
  }
})
</script>

<template>
  <div>
    <div class="d-flex align-center gap-x-2 mb-4">
      <VBtn
        icon
        variant="text"
        size="small"
        :to="{ name: 'company-my-deliveries' }"
        :aria-label="t('deliveries.title')"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <h4 class="text-h4 text-truncate">
        {{ delivery?.reference || t('deliveries.delivery') }}
      </h4>
      <VChip
        v-if="delivery"
        :color="shipmentStatusColor(delivery.status)"
        size="small"
        label
      >
        {{ statusLabel(delivery.status) }}
      </VChip>
    </div>

    <VProgressLinear
      v-if="isLoading"
      indeterminate
    />

    <template v-else-if="delivery">
      <VAlert
        v-if="successMessage"
        type="success"
        variant="tonal"
        class="mb-4"
        closable
        @click:close="successMessage = ''"
      >
        {{ successMessage }}
      </VAlert>
      <VAlert
        v-if="errorMessage"
        type="error"
        variant="tonal"
        class="mb-4"
        closable
        @click:close="errorMessage = ''"
      >
        {{ errorMessage }}
      </VAlert>

      <!-- ADR-822 — l'étape suivante d'abord, en grand (usage au téléphone) -->
      <VBtn
        v-for="tr in availableTransitions"
        :key="tr.status"
        v-can="'shipments.manage_status'"
        :color="tr.color"
        :prepend-icon="tr.icon"
        :loading="isChangingStatus"
        size="large"
        block
        class="mb-6"
        :data-test="`delivery-to-${tr.status}`"
        @click="changeStatus(tr.status)"
      >
        {{ tr.label }}
      </VBtn>

      <VCard :title="t('shipments.detail.route')">
        <VCardText>
          <VTimeline
            side="end"
            align="start"
            density="compact"
            truncate-line="both"
          >
            <VTimelineItem
              dot-color="primary"
              size="x-small"
            >
              <div class="text-caption text-disabled">
                {{ t('deliveries.origin') }}
              </div>
              <div class="text-body-1">
                {{ delivery.origin_address || t('shipments.detail.notProvided') }}
              </div>
            </VTimelineItem>
            <VTimelineItem
              dot-color="success"
              size="x-small"
            >
              <div class="text-caption text-disabled">
                {{ t('deliveries.destination') }}
              </div>
              <div class="text-body-1 font-weight-medium">
                {{ delivery.destination_address || t('shipments.detail.notProvided') }}
              </div>
            </VTimelineItem>
          </VTimeline>
        </VCardText>
        <VDivider />
        <VList density="compact">
          <VListItem prepend-icon="tabler-calendar">
            <VListItemSubtitle>{{ t('deliveries.scheduledDate') }}</VListItemSubtitle>
            <VListItemTitle>{{ delivery.scheduled_at ? formatDate(delivery.scheduled_at) : t('shipments.notScheduled') }}</VListItemTitle>
          </VListItem>
          <VListItem prepend-icon="tabler-notes">
            <VListItemSubtitle>{{ t('deliveries.notes') }}</VListItemSubtitle>
            <VListItemTitle class="text-wrap">
              {{ delivery.notes || '—' }}
            </VListItemTitle>
          </VListItem>
          <VListItem
            v-if="delivery.driver_identifier"
            prepend-icon="tabler-id-badge-2"
          >
            <VListItemSubtitle>{{ t('shipments.detail.myIdentifier') }}</VListItemSubtitle>
            <VListItemTitle>{{ delivery.driver_identifier }}</VListItemTitle>
          </VListItem>
        </VList>
      </VCard>

      <div
        v-if="!availableTransitions.length"
        class="text-body-2 text-disabled text-center mt-6"
      >
        {{ t('deliveries.noActionsAvailable') }}
      </div>
    </template>
  </div>
</template>
