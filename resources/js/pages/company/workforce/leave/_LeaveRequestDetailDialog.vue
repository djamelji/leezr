<script setup>
// W-G3 (ADR-643) — détail d'une demande + approbation/refus INLINE (réutilise les use cases
// existants). Affiche solde (si autorisé), absences SIMULTANÉES (indicateur factuel, pas de faux
// seuil de sous-effectif). Le motif n'est affiché que si le backend l'a renvoyé (gating serveur).
import { useAppToast } from '@/composables/useAppToast'
import { useLeavesStore } from '@/modules/company/workforce/leaves.store'
import { useCan } from '@/composables/useCan'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  request: { type: Object, default: null }, // extendedProps de l'événement calendrier
  allEvents: { type: Array, default: () => [] }, // événements chargés (pour les simultanés)
})
const emit = defineEmits(['update:modelValue', 'updated'])

const { t, locale } = useI18n()
const { toast } = useAppToast()
const { can } = useCan()
const store = useLeavesStore()

const isOpen = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })

const detail = ref(null)
const balance = ref(null)
const loading = ref(false)
const rejecting = ref(false)
const actionLoading = ref(false)
const rejectNote = ref('')

const statusColor = s => ({ pending: 'warning', approved: 'success', consumed: 'info', rejected: 'error', cancelled: 'secondary' }[s] || 'secondary')
const fmt = d => d ? new Date(d).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const canApprove = computed(() => can('workforce.leave_approve') && props.request?.status === 'pending')

// Absences simultanées : chevauchent la période de la demande, même entreprise, hors elle-même.
const overlaps = computed(() => {
  if (!props.request) return []
  const a0 = props.request.date_from
  const a1 = props.request.date_to

  return props.allEvents.filter(e =>
    e.id !== props.request.id && e.date_from <= a1 && e.date_to >= a0,
  )
})

const load = async () => {
  if (!props.request) return
  loading.value = true
  detail.value = null
  balance.value = null
  rejecting.value = false
  rejectNote.value = ''
  try {
    detail.value = await store.fetchLeaveRequest(props.request.id)
    try {
      const balances = await store.fetchBalances(props.request.employee_id)
      balance.value = (balances || []).find(b => b.leave_type_name === props.request.leave_type) || null
    } catch { balance.value = null }
  } finally {
    loading.value = false
  }
}

watch(() => props.modelValue, v => { if (v) load() })

const approve = async () => {
  actionLoading.value = true
  try {
    await store.approveLeaveRequest(props.request.id)
    toast(t('leaves.approved'), 'success')
    emit('updated')
    isOpen.value = false
  } catch (e) {
    toast(e.response?.data?.message || t('common.error'), 'error')
  } finally {
    actionLoading.value = false
  }
}

const reject = async () => {
  actionLoading.value = true
  try {
    await store.rejectLeaveRequest(props.request.id, { reviewNote: rejectNote.value || undefined })
    toast(t('leaves.rejected'), 'success')
    emit('updated')
    isOpen.value = false
  } catch (e) {
    toast(e.response?.data?.message || t('common.error'), 'error')
  } finally {
    actionLoading.value = false
  }
}
</script>

<template>
  <VDialog
    v-model="isOpen"
    max-width="520"
  >
    <VCard v-if="request">
      <VCardItem>
        <VCardTitle>{{ request.employee_name }}</VCardTitle>
        <VCardSubtitle>{{ request.leave_type }}</VCardSubtitle>
        <template #append>
          <VChip
            :color="statusColor(request.status)"
            size="small"
            variant="tonal"
          >
            {{ t('workforce.statuses.leave.' + request.status, request.status) }}
          </VChip>
        </template>
      </VCardItem>

      <VCardText>
        <div class="d-flex flex-column gap-2">
          <div class="d-flex justify-space-between">
            <span class="text-medium-emphasis">{{ t('leaves.calendar.period') }}</span>
            <span>{{ fmt(request.date_from) }} → {{ fmt(request.date_to) }}</span>
          </div>
          <div class="d-flex justify-space-between">
            <span class="text-medium-emphasis">{{ t('leaves.calendar.days') }}</span>
            <span>{{ request.days_count }}</span>
          </div>

          <!-- Solde (si autorisé / disponible) -->
          <VDivider class="my-2" />
          <div class="text-overline">
            {{ t('leaves.balances.title') }}
          </div>
          <div
            v-if="balance"
            class="d-flex gap-4 flex-wrap text-body-2"
          >
            <span>{{ t('leaves.balances.available') }}: <strong>{{ balance.available / 100 }}</strong></span>
            <span>{{ t('leaves.balances.reserved') }}: {{ balance.reserved / 100 }}</span>
            <span>{{ t('leaves.balances.consumed') }}: {{ balance.consumed / 100 }}</span>
          </div>
          <div
            v-else
            class="text-caption text-disabled"
          >
            {{ t('leaves.balances.unavailable') }}
          </div>

          <!-- Motif (gating serveur) -->
          <template v-if="detail">
            <VDivider class="my-2" />
            <div class="text-overline">
              {{ t('leaves.fields.reason') }}
            </div>
            <div
              v-if="detail.reason_hidden"
              class="text-caption text-disabled"
            >
              {{ t('leaves.reasonHidden') }}
            </div>
            <div
              v-else
              class="text-body-2"
            >
              {{ detail.reason || '—' }}
            </div>
          </template>

          <!-- Absences simultanées (indicateur factuel) -->
          <VDivider class="my-2" />
          <div class="text-overline">
            {{ t('leaves.calendar.simultaneous') }}
          </div>
          <VAlert
            v-if="overlaps.length"
            type="info"
            variant="tonal"
            density="compact"
          >
            {{ t('leaves.calendar.simultaneousCount', { count: overlaps.length }) }}
            <div class="mt-1 text-caption">
              {{ overlaps.map(o => o.employee_name).join(', ') }}
            </div>
          </VAlert>
          <div
            v-else
            class="text-caption text-disabled"
          >
            {{ t('leaves.calendar.noSimultaneous') }}
          </div>
        </div>

        <!-- Zone de refus -->
        <VTextarea
          v-if="rejecting"
          v-model="rejectNote"
          :label="t('leaves.fields.reviewNote')"
          rows="2"
          class="mt-4"
          auto-grow
        />
      </VCardText>

      <VCardText class="d-flex justify-end gap-3 pt-0">
        <VBtn
          variant="outlined"
          color="secondary"
          @click="isOpen = false"
        >
          {{ t('common.close') }}
        </VBtn>
        <template v-if="canApprove">
          <VBtn
            v-if="!rejecting"
            color="error"
            variant="tonal"
            prepend-icon="tabler-x"
            @click="rejecting = true"
          >
            {{ t('leaves.actions.reject') }}
          </VBtn>
          <VBtn
            v-else
            color="error"
            :loading="actionLoading"
            @click="reject"
          >
            {{ t('leaves.actions.confirmReject') }}
          </VBtn>
          <VBtn
            v-if="!rejecting"
            color="success"
            prepend-icon="tabler-check"
            :loading="actionLoading"
            @click="approve"
          >
            {{ t('leaves.actions.approve') }}
          </VBtn>
        </template>
      </VCardText>
    </VCard>
  </VDialog>
</template>
