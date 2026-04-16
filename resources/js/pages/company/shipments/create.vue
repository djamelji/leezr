<script setup>
import { useShipmentStore } from '@/modules/logistics-shipments/stores/shipment.store'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations', permission: 'shipments.create' } })

const { t } = useI18n()
const shipmentStore = useShipmentStore()
const router = useRouter()
const { toast } = useAppToast()

const formRef = ref()

const initialForm = {
  origin_address: '',
  destination_address: '',
  scheduled_at: '',
  notes: '',
}

const form = ref({ ...initialForm })

// Unsaved changes guard
const { markClean } = useUnsavedChanges(form, ref({ ...initialForm }))

// Submit via useAsyncAction
const { isLoading: isSubmitting, execute: submitForm } = useAsyncAction(async () => {
  const shipment = await shipmentStore.createShipment({
    origin_address: form.value.origin_address || null,
    destination_address: form.value.destination_address || null,
    scheduled_at: form.value.scheduled_at || null,
    notes: form.value.notes || null,
  })

  markClean()
  toast(t('shipments.created'), 'success')
  await router.push({ name: 'company-shipments-id', params: { id: shipment.id } })

  return shipment
})

const handleSubmit = async () => {
  const { valid } = await formRef.value.validate()
  if (!valid) return
  await submitForm()
}
</script>

<template>
  <div>
    <PageBreadcrumbs
      :items="[
        { title: t('shipments.title'), to: { name: 'company-shipments' } },
        { title: t('shipments.newShipment') },
      ]"
    />

    <VCard>
      <VCardText>
        <VForm
          ref="formRef"
          @submit.prevent="handleSubmit"
        >
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.origin_address"
                :label="t('shipments.originAddress')"
                :placeholder="t('shipments.pickupLocation')"
                :rules="[requiredValidator]"
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
                :rules="[requiredValidator]"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <div class="d-flex align-center gap-1">
                <AppTextField
                  v-model="form.scheduled_at"
                  :label="t('shipments.scheduledDate')"
                  type="datetime-local"
                  class="flex-grow-1"
                />
                <AppTooltipHelp :text="t('shipments.scheduledDateHelp')" />
              </div>
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
                  :disabled="isSubmitting"
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
