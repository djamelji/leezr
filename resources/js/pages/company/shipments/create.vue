<script setup>
import { useShipmentStore } from '@/core/stores/shipment'

definePage({ meta: { module: 'logistics_shipments' } })

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
    errorMessage.value = error?.data?.message || 'Failed to create shipment.'
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
        New Shipment
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
                label="Origin Address"
                placeholder="Pickup location"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.destination_address"
                label="Destination Address"
                placeholder="Delivery location"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.scheduled_at"
                label="Scheduled Date"
                type="datetime-local"
              />
            </VCol>

            <VCol cols="12">
              <AppTextField
                v-model="form.notes"
                label="Notes"
                placeholder="Additional information..."
              />
            </VCol>

            <VCol cols="12">
              <div class="d-flex gap-4">
                <VBtn
                  type="submit"
                  :loading="isSubmitting"
                >
                  Create Shipment
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  :to="{ name: 'company-shipments' }"
                >
                  Cancel
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
