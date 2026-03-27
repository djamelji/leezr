<script setup>
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
    <div class="d-flex align-center gap-x-3 mb-6">
      <VBtn
        icon
        variant="text"
        size="small"
        :to="{ name: 'company-my-deliveries' }"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <h4 class="text-h4">
        {{ delivery?.reference || t('deliveries.delivery') }}
      </h4>
      <VChip
        v-if="delivery"
        :color="statusColor(delivery.status)"
        size="small"
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
        class="mb-4"
        closable
        @click:close="successMessage = ''"
      >
        {{ successMessage }}
      </VAlert>

      <VAlert
        v-if="errorMessage"
        type="error"
        class="mb-4"
        closable
        @click:close="errorMessage = ''"
      >
        {{ errorMessage }}
      </VAlert>

      <VRow>
        <!-- Delivery details -->
        <VCol
          cols="12"
          md="8"
        >
          <VCard>
            <VCardTitle>{{ t('deliveries.deliveryDetails') }}</VCardTitle>
            <VCardText>
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('deliveries.reference') }}
                  </div>
                  <div class="text-body-1 font-weight-medium">
                    {{ delivery.reference }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('deliveries.scheduledDate') }}
                  </div>
                  <div class="text-body-1">
                    {{ formatDate(delivery.scheduled_at) }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('deliveries.origin') }}
                  </div>
                  <div class="text-body-1">
                    {{ delivery.origin_address || '—' }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('deliveries.destination') }}
                  </div>
                  <div class="text-body-1">
                    {{ delivery.destination_address || '—' }}
                  </div>
                </VCol>

                <VCol cols="12">
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('deliveries.notes') }}
                  </div>
                  <div class="text-body-1">
                    {{ delivery.notes || '—' }}
                  </div>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Status actions -->
        <VCol
          cols="12"
          md="4"
        >
          <VCard>
            <VCardTitle>{{ t('deliveries.updateStatus') }}</VCardTitle>
            <VCardText>
              <template v-if="availableTransitions.length">
                <div class="d-flex flex-column gap-3">
                  <VBtn
                    v-for="transition in availableTransitions"
                    :key="transition.status"
                    :color="transition.color"
                    :prepend-icon="transition.icon"
                    :loading="isChangingStatus"
                    block
                    @click="changeStatus(transition.status)"
                  >
                    {{ transition.label }}
                  </VBtn>
                </div>
              </template>
              <template v-else>
                <div class="text-body-2 text-disabled text-center py-4">
                  {{ t('deliveries.noActionsAvailable') }}
                </div>
              </template>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
