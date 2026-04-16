<script setup>
import { formatDate } from '@/utils/datetime'
import { useAuthStore } from '@/core/stores/auth'
import { useMembersStore } from '@/modules/company/members/members.store'
import { useShipmentStore } from '@/modules/logistics-shipments/stores/shipment.store'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations', permission: 'shipments.view' } })

const { t } = useI18n()
const auth = useAuthStore()
const membersStore = useMembersStore()
const shipmentStore = useShipmentStore()
const route = useRoute()
const router = useRouter()

const isLoading = ref(true)
const isChangingStatus = ref(false)
const isAssigning = ref(false)
const selectedUserId = ref(null)
const errorMessage = ref('')
const successMessage = ref('')

const canManage = computed(() => auth.hasPermission('shipments.manage_status'))
const canAssign = computed(() => auth.hasPermission('shipments.assign'))

const memberOptions = computed(() => {
  return membersStore.members
    .filter(m => !m._isProtected && m.company_role)
    .map(m => ({
      title: `${m.user.display_name} — ${m.company_role.name}`,
      value: m.user.id,
    }))
})

const shipment = computed(() => shipmentStore.currentShipment)

const transitions = computed(() => ({
  draft: [
    { status: 'planned', label: t('shipments.markAsPlanned'), color: 'info', icon: 'tabler-calendar-check' },
    { status: 'canceled', label: t('shipments.cancelShipment'), color: 'error', icon: 'tabler-x' },
  ],
  planned: [
    { status: 'in_transit', label: t('shipments.startTransit'), color: 'warning', icon: 'tabler-truck' },
    { status: 'canceled', label: t('shipments.cancelShipment'), color: 'error', icon: 'tabler-x' },
  ],
  in_transit: [
    { status: 'delivered', label: t('shipments.markDelivered'), color: 'success', icon: 'tabler-check' },
    { status: 'canceled', label: t('shipments.cancelShipment'), color: 'error', icon: 'tabler-x' },
  ],
  delivered: [],
  canceled: [],
}))

const availableTransitions = computed(() => {
  if (!shipment.value)
    return []

  return transitions.value[shipment.value.status] || []
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
    draft: t('shipments.statusDraft'),
    planned: t('shipments.statusPlanned'),
    in_transit: t('shipments.statusInTransit'),
    delivered: t('shipments.statusDelivered'),
    canceled: t('shipments.statusCanceled'),
  }

  return labels[status] || status
}

const changeStatus = async newStatus => {
  isChangingStatus.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    await shipmentStore.changeStatus(shipment.value.id, newStatus)
    successMessage.value = t('shipments.statusChangedTo', { status: statusLabel(newStatus) })
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('shipments.failedToChangeStatus')
  }
  finally {
    isChangingStatus.value = false
  }
}

const assignShipment = async () => {
  isAssigning.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    await shipmentStore.assignShipment(shipment.value.id, selectedUserId.value || null)
    successMessage.value = selectedUserId.value ? t('shipments.shipmentAssigned') : t('shipments.assignmentRemoved')
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('shipments.failedToAssign')
  }
  finally {
    isAssigning.value = false
  }
}

onMounted(async () => {
  try {
    await shipmentStore.fetchShipment(route.params.id)

    if (canAssign.value) {
      await membersStore.fetchMembers()
    }

    // Init selected user from current assignment
    selectedUserId.value = shipmentStore.currentShipment?.assigned_to_user_id || null
  }
  catch {
    await router.push({ name: 'company-shipments' })
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
        :to="{ name: 'company-shipments' }"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <h4 class="text-h4">
        {{ shipment?.reference || t('shipments.shipment') }}
      </h4>
      <VChip
        v-if="shipment"
        :color="statusColor(shipment.status)"
        size="small"
      >
        {{ statusLabel(shipment.status) }}
      </VChip>
    </div>

    <VProgressLinear
      v-if="isLoading"
      indeterminate
    />

    <template v-else-if="shipment">
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
        <!-- Shipment details -->
        <VCol
          cols="12"
          md="8"
        >
          <VCard>
            <VCardTitle>{{ t('shipments.shipmentDetails') }}</VCardTitle>
            <VCardText>
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('shipments.reference') }}
                  </div>
                  <div class="text-body-1 font-weight-medium">
                    {{ shipment.reference }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('shipments.scheduledDate') }}
                  </div>
                  <div class="text-body-1">
                    {{ formatDate(shipment.scheduled_at) }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('shipments.origin') }}
                  </div>
                  <div class="text-body-1">
                    {{ shipment.origin_address || '—' }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('shipments.destination') }}
                  </div>
                  <div class="text-body-1">
                    {{ shipment.destination_address || '—' }}
                  </div>
                </VCol>

                <VCol cols="12">
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('shipments.notes') }}
                  </div>
                  <div class="text-body-1">
                    {{ shipment.notes || '—' }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('shipments.assignedTo') }}
                  </div>
                  <div class="text-body-1">
                    <template v-if="shipment.assigned_to">
                      {{ shipment.assigned_to.display_name }}
                    </template>
                    <span
                      v-else
                      class="text-disabled"
                    >{{ t('shipments.unassigned') }}</span>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('shipments.createdBy') }}
                  </div>
                  <div class="text-body-1">
                    <template v-if="shipment.created_by">
                      {{ shipment.created_by.display_name }}
                    </template>
                    <span
                      v-else
                      class="text-disabled"
                    >—</span>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="6"
                >
                  <div class="text-body-2 text-disabled mb-1">
                    {{ t('shipments.createdAt') }}
                  </div>
                  <div class="text-body-1">
                    {{ formatDate(shipment.created_at) }}
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
            <VCardTitle>{{ t('common.actions') }}</VCardTitle>
            <VCardText>
              <template v-if="canManage && availableTransitions.length">
                <div class="d-flex flex-column gap-3">
                  <VBtn
                    v-for="transition in availableTransitions"
                    :key="transition.status"
                    v-can="'shipments.manage_status'"
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
                  {{ t('shipments.noActionsAvailable') }}
                </div>
              </template>
            </VCardText>
          </VCard>

          <!-- Assignment card -->
          <VCard
            v-if="canAssign"
            v-can="'shipments.assign'"
            class="mt-4"
          >
            <VCardTitle>{{ t('shipments.assignment') }}</VCardTitle>
            <VCardText>
              <AppSelect
                v-model="selectedUserId"
                :items="memberOptions"
                :placeholder="t('shipments.selectMember')"
                clearable
                class="mb-3"
              />
              <VBtn
                v-can="'shipments.assign'"
                color="primary"
                prepend-icon="tabler-user-check"
                :loading="isAssigning"
                block
                @click="assignShipment"
              >
                {{ selectedUserId ? t('shipments.assign') : t('shipments.unassign') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
