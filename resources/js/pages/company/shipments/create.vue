<script setup>
import { useShipmentStore } from '@/modules/logistics-shipments/stores/shipment.store'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations', permission: 'shipments.create' } })

const { t } = useI18n()
const shipmentStore = useShipmentStore()
const router = useRouter()

const isSubmitting = ref(false)
const errorMessage = ref('')

const form = ref({
  origin_address: '',
  destination_address: '',
  scheduled_at: '',
  notes: '',
})

const handleSubmit = async () => {
  isSubmitting.value = true
  errorMessage.value = ''

  try {
    const shipment = await shipmentStore.createShipment({
      origin_address: form.value.origin_address || null,
      destination_address: form.value.destination_address || null,
      scheduled_at: form.value.scheduled_at || null,
      notes: form.value.notes || null,
    })

    await router.push({ name: 'company-shipments-id', params: { id: shipment.id } })
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('shipments.failedToCreate')
  }
  finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div>
    <div class="d-flex align-center gap-x-3 mb-6">
      <VBtn
        icon
        variant="text"
        size="small"
        :to="{ name: 'company-shipments' }"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <h4 class="text-h4">
        {{ t('shipments.newShipment') }}
      </h4>
    </div>

    <VCard>
      <VCardText>
        <VAlert
          v-if="errorMessage"
          type="error"
          class="mb-6"
          closable
          @click:close="errorMessage = ''"
        >
          {{ errorMessage }}
        </VAlert>

        <VForm @submit.prevent="handleSubmit">
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.origin_address"
                :label="t('shipments.originAddress')"
                :placeholder="t('shipments.pickupLocation')"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.destination_address"
                :label="t('shipments.destinationAddress')"
                :placeholder="t('shipments.deliveryLocation')"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.scheduled_at"
                :label="t('shipments.scheduledDate')"
                type="datetime-local"
              />
            </VCol>

            <VCol cols="12">
              <AppTextField
                v-model="form.notes"
                :label="t('shipments.notes')"
                :placeholder="t('shipments.additionalInfo')"
              />
            </VCol>

            <VCol cols="12">
              <div class="d-flex gap-4">
                <VBtn
                  type="submit"
                  :loading="isSubmitting"
                >
                  {{ t('shipments.createShipment') }}
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  :to="{ name: 'company-shipments' }"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
