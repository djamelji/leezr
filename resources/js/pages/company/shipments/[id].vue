<script setup>
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import { shipmentStatusColor } from '@/utils/shipments'
import { formatDate } from '@/utils/datetime'
import { $api } from '@/utils/api'
import { useAuthStore } from '@/core/stores/auth'
import { useShipmentStore } from '@/modules/logistics-shipments/stores/shipment.store'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations', permission: 'shipments.view' } })

const { t } = useI18n()
const auth = useAuthStore()
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

// ADR-822 — personnes affectables avec l'identifiant chauffeur lu dans le champ choisi par la société.
// Une valeur manquante bloque SEULEMENT l'affectation de cette personne (contrôle serveur identique).
const assignees = ref({ identifier_required: false, identifier_label: null, people: [] })
const memberOptions = computed(() => assignees.value.people.map(p => ({
  title: p.name,
  value: p.user_id,
  role: p.role,
  identifier: p.driver_identifier,
  props: { disabled: assignees.value.identifier_required && !p.driver_identifier },
})))
const STEPS = ['draft', 'planned', 'in_transit', 'delivered']
const stepIndex = computed(() => STEPS.indexOf(shipment.value?.status))
const STEP_ICON = { draft: 'tabler-pencil', planned: 'tabler-calendar-check', in_transit: 'tabler-truck', delivered: 'tabler-circle-check' }
const forward = computed(() => availableTransitions.value.filter(tr => tr.status !== 'canceled'))
const canCancel = computed(() => availableTransitions.value.some(tr => tr.status === 'canceled'))
const confirmCancel = ref(false)

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

    if (canAssign.value)
      assignees.value = await $api('/shipments/assignees').catch(() => assignees.value)

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

// ADR-818 (chantier H) — planification après création (date, adresses, notes) tant que l'expédition n'est pas partie.
const canPlan = computed(() => auth.hasPermission('shipments.create') && ['draft', 'planned'].includes(shipment.value?.status))
const planOpen = ref(false)
const planSaving = ref(false)
const planForm = ref({})
const openPlan = () => {
  planForm.value = { scheduled_at: shipment.value.scheduled_at ? String(shipment.value.scheduled_at).slice(0, 16) : '', origin_address: shipment.value.origin_address || '', destination_address: shipment.value.destination_address || '', notes: shipment.value.notes || '' }
  planOpen.value = true
}
const savePlan = async () => {
  planSaving.value = true
  errorMessage.value = ''
  try {
    await $api(`/shipments/${shipment.value.id}`, { method: 'PUT', body: { ...planForm.value, scheduled_at: planForm.value.scheduled_at || null } })
    await shipmentStore.fetchShipment(shipment.value.id)
    successMessage.value = t('shipments.planned')
    planOpen.value = false
  }
  catch (e) { errorMessage.value = Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error') }
  finally { planSaving.value = false }
}
</script>

<template>
  <div>
    <!-- ADR-861 (L7.10) : shared page header — way back, reference as title, status chip, plan as page action -->
    <AppPageHeader
      :back="{ to: { name: 'company-shipments' }, label: t('l7.family.logistics') }"
      :eyebrow="t('shipments.shipment')"
      :title="shipment?.reference || t('shipments.shipment')"
      data-test="shipment-header"
    >
      <template
        v-if="shipment"
        #meta
      >
        <VChip
          :color="shipmentStatusColor(shipment.status)"
          size="small"
          variant="tonal"
          label
        >
          {{ statusLabel(shipment.status) }}
        </VChip>
      </template>
      <template
        v-if="canPlan"
        #actions
      >
        <VBtn
          variant="tonal"
          prepend-icon="tabler-calendar-event"
          data-test="shipment-plan"
          @click="openPlan"
        >
          {{ t('shipments.plan') }}
        </VBtn>
      </template>
    </AppPageHeader>

    <AppDialog
      v-model="planOpen"
      :title="t('shipments.plan')"
      :max-width="560"
    >
      <AppTextField
        v-model="planForm.scheduled_at"
        type="datetime-local"
        :label="t('shipments.scheduledDate')"
        class="mb-3"
        data-test="shipment-plan-date"
      />
      <AppTextarea
        v-model="planForm.origin_address"
        rows="2"
        :label="t('shipments.originAddress')"
        class="mb-3"
      />
      <AppTextarea
        v-model="planForm.destination_address"
        rows="2"
        :label="t('shipments.destinationAddress')"
        class="mb-3"
      />
      <AppTextarea
        v-model="planForm.notes"
        rows="2"
        :label="t('shipments.notes')"
      />
      <template #actions>
        <VBtn
          variant="text"
          @click="planOpen = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="planSaving"
          data-test="shipment-plan-save"
          @click="savePlan"
        >
          {{ t('common.save') }}
        </VBtn>
      </template>
    </AppDialog>

    <VProgressLinear
      v-if="isLoading"
      indeterminate
    />

    <template v-else-if="shipment">
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
        data-test="shipment-error"
        @click:close="errorMessage = ''"
      >
        {{ errorMessage }}
      </VAlert>

      <!-- Avancement : où en est l'expédition, et l'étape suivante en un clic -->
      <VCard
        class="mb-6"
        data-test="shipment-progress"
      >
        <VCardText>
          <VAlert
            v-if="shipment.status === 'canceled'"
            type="error"
            variant="tonal"
            icon="tabler-ban"
          >
            {{ t('shipments.detail.canceled') }}
          </VAlert>
          <div
            v-else
            class="d-flex flex-wrap align-center gap-4"
          >
            <template
              v-for="(st, i) in STEPS"
              :key="st"
            >
              <div class="d-flex align-center gap-2">
                <VAvatar
                  size="34"
                  :color="i <= stepIndex ? shipmentStatusColor(st) : 'secondary'"
                  :variant="i === stepIndex ? 'elevated' : 'tonal'"
                >
                  <VIcon
                    :icon="STEP_ICON[st]"
                    size="18"
                  />
                </VAvatar>
                <span :class="i === stepIndex ? 'font-weight-medium text-high-emphasis' : 'text-medium-emphasis'">{{ statusLabel(st) }}</span>
              </div>
              <VIcon
                v-if="i < STEPS.length - 1"
                icon="tabler-chevron-right"
                size="18"
                class="text-disabled"
              />
            </template>
          </div>
        </VCardText>
        <template v-if="canManage && (forward.length || canCancel)">
          <VDivider />
          <VCardActions class="flex-wrap gap-2 px-6 py-4">
            <VBtn
              v-for="tr in forward"
              :key="tr.status"
              :color="tr.color"
              variant="elevated"
              :prepend-icon="tr.icon"
              :loading="isChangingStatus"
              :data-test="`shipment-to-${tr.status}`"
              @click="changeStatus(tr.status)"
            >
              {{ tr.label }}
            </VBtn>
            <VSpacer />
            <VBtn
              v-if="canCancel"
              color="error"
              variant="text"
              prepend-icon="tabler-x"
              data-test="shipment-cancel"
              @click="confirmCancel = true"
            >
              {{ t('shipments.cancelShipment') }}
            </VBtn>
          </VCardActions>
        </template>
      </VCard>

      <VRow>
        <VCol
          cols="12"
          md="7"
        >
          <VCard :title="t('shipments.detail.route')">
            <VCardText>
              <VTimeline
                side="end"
                align="start"
                density="compact"
                truncate-line="both"
                class="v-timeline-density-compact"
              >
                <VTimelineItem
                  dot-color="primary"
                  size="x-small"
                >
                  <div class="text-caption text-disabled">
                    {{ t('shipments.origin') }}
                  </div>
                  <div class="text-body-1">
                    {{ shipment.origin_address || t('shipments.detail.notProvided') }}
                  </div>
                </VTimelineItem>
                <VTimelineItem
                  dot-color="success"
                  size="x-small"
                >
                  <div class="text-caption text-disabled">
                    {{ t('shipments.destination') }}
                  </div>
                  <div class="text-body-1">
                    {{ shipment.destination_address || t('shipments.detail.notProvided') }}
                  </div>
                </VTimelineItem>
              </VTimeline>
            </VCardText>
            <VDivider />
            <VList
              density="compact"
              class="py-2"
            >
              <VListItem prepend-icon="tabler-calendar">
                <VListItemSubtitle>{{ t('shipments.scheduledDate') }}</VListItemSubtitle>
                <VListItemTitle>{{ shipment.scheduled_at ? formatDate(shipment.scheduled_at) : t('shipments.notScheduled') }}</VListItemTitle>
              </VListItem>
              <VListItem prepend-icon="tabler-notes">
                <VListItemSubtitle>{{ t('shipments.notes') }}</VListItemSubtitle>
                <VListItemTitle class="text-wrap">
                  {{ shipment.notes || '—' }}
                </VListItemTitle>
              </VListItem>
              <VListItem prepend-icon="tabler-user-plus">
                <VListItemSubtitle>{{ t('shipments.createdBy') }}</VListItemSubtitle>
                <VListItemTitle>{{ shipment.created_by?.display_name || '—' }} · {{ formatDate(shipment.created_at) }}</VListItemTitle>
              </VListItem>
            </VList>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          md="5"
        >
          <VCard
            :title="t('shipments.detail.driver')"
            data-test="shipment-driver"
          >
            <VCardText>
              <div
                v-if="shipment.assigned_to"
                class="d-flex align-center gap-3"
              >
                <VAvatar
                  color="primary"
                  variant="tonal"
                >
                  {{ (shipment.assigned_to.display_name || '?').slice(0, 2).toUpperCase() }}
                </VAvatar>
                <div>
                  <div class="text-body-1 font-weight-medium">
                    {{ shipment.assigned_to.display_name }}
                  </div>
                  <VChip
                    v-if="shipment.driver_identifier"
                    size="x-small"
                    label
                    color="info"
                    variant="tonal"
                    data-test="shipment-driver-identifier"
                  >
                    {{ shipment.driver_identifier }}
                  </VChip>
                </div>
              </div>
              <div
                v-else
                class="text-body-2 text-disabled"
              >
                {{ t('shipments.unassigned') }}
              </div>
            </VCardText>

            <template v-if="canAssign && !['delivered', 'canceled'].includes(shipment.status)">
              <VDivider />
              <VCardText>
                <AppSelect
                  v-model="selectedUserId"
                  :items="memberOptions"
                  :label="t('shipments.detail.assignTo')"
                  :placeholder="t('shipments.selectMember')"
                  clearable
                  data-test="shipment-assignee"
                >
                  <template #item="{ props: itemProps, item }">
                    <VListItem
                      v-bind="itemProps"
                      :subtitle="[item.raw.role, item.raw.identifier || (assignees.identifier_required ? t('shipments.detail.identifierMissing') : null)].filter(Boolean).join(' · ')"
                    />
                  </template>
                </AppSelect>
                <div
                  v-if="assignees.identifier_required"
                  class="text-caption text-medium-emphasis mt-2"
                >
                  {{ t('shipments.detail.identifierRule', { field: assignees.identifier_label }) }}
                </div>
                <VBtn
                  class="mt-4"
                  color="primary"
                  prepend-icon="tabler-user-check"
                  :loading="isAssigning"
                  :disabled="selectedUserId === (shipment.assigned_to_user_id || null)"
                  block
                  data-test="shipment-assign"
                  @click="assignShipment"
                >
                  {{ selectedUserId ? t('shipments.assign') : t('shipments.unassign') }}
                </VBtn>
              </VCardText>
            </template>
          </VCard>
        </VCol>
      </VRow>

      <VDialog
        v-model="confirmCancel"
        max-width="440"
      >
        <VCard :title="t('shipments.cancelShipment')">
          <VCardText>{{ t('shipments.detail.cancelConfirm') }}</VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              @click="confirmCancel = false"
            >
              {{ t('common.cancel') }}
            </VBtn>
            <VBtn
              color="error"
              variant="elevated"
              data-test="shipment-cancel-confirm"
              @click="confirmCancel = false; changeStatus('canceled')"
            >
              {{ t('shipments.cancelShipment') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>
