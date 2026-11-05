<script setup>
// PRODUCT-UX-2 (LOT B2, P0) — panneau « Journée collaborateur » : depuis une ligne du cockpit, tout
// le contexte PRÉVU / RÉEL / ANALYSE d'une journée + les ACTIONS AUTORISÉES (corriger le pointage via
// le workflow TRACÉ existant, ouvrir la fiche, basculer sur Planning/Présences). Réutilise les
// services existants (cockpit board + correctTimeEntry + endpoints de lecture). Aucun 2ᵉ moteur.
import { $api } from '@/utils/api'
import { useTimesheetsStore } from '@/modules/company/workforce/timesheets.store'
import { usePlanningStore } from '@/modules/company/workforce/planning.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  row: { type: Object, default: null },
  date: { type: String, default: '' },
  canManage: { type: Boolean, default: false },
  canPlanningManage: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'corrected', 'open-fiche', 'go-planning', 'go-presence'])

const { t, locale } = useI18n()
const { toast } = useAppToast()
const store = useTimesheetsStore()
const planningStore = usePlanningStore()

const isOpen = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const r = computed(() => props.row || {})

const dayShifts = ref([])
const dayEntries = ref([])
const loadingDetail = ref(false)

const loadDetail = async () => {
  if (!props.row?.employee_id || !props.date) return
  loadingDetail.value = true
  dayShifts.value = []
  dayEntries.value = []
  try {
    const [s, e] = await Promise.all([
      $api(`/workforce/planning/shifts?employee_id=${props.row.employee_id}&from=${props.date}&to=${props.date}`).catch(() => null),
      $api(`/workforce/time-entries?employee_id=${props.row.employee_id}&from=${props.date}&to=${props.date}`).catch(() => null),
    ])
    dayShifts.value = s?.data ?? s ?? []
    dayEntries.value = e?.data ?? e ?? []
  } finally {
    loadingDetail.value = false
  }
}
watch(() => [props.modelValue, props.row?.employee_id], ([open]) => { if (open) loadDetail() }, { immediate: true })

// ── Helpers ──
const fmtTime = iso => iso ? new Date(iso).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) : null
const fmtMin = m => (m === null || m === undefined) ? '—' : `${Math.floor(m / 60)}h${String(m % 60).padStart(2, '0')}`
const reconColor = x => ({ matched: 'success', ambiguous: 'warning', not_clocked: 'secondary', no_shift: 'info' }[x] || 'secondary')
const reconLabel = x => t(`timeCockpit.recon.${x}`, x)
const statusColor = s => ({ working: 'success', on_break: 'warning', completed: 'info', on_leave: 'primary', idle: 'secondary' }[s] || 'secondary')
const shiftStatusLabel = s => t(`workforce.statuses.shift.${s}`, s)

// Analyse lisible (états métier précis — jamais "anomalie" automatique).
const findings = computed(() => {
  const f = []
  const x = r.value
  if (x.on_leave) f.push({ icon: 'tabler-beach', color: 'primary', text: t('dayPanel.findings.onLeave') })
  if (x.reconciliation === 'not_clocked') f.push({ icon: 'tabler-user-off', color: 'warning', text: t('dayPanel.findings.notClocked') })
  if (x.reconciliation === 'no_shift') f.push({ icon: 'tabler-calendar-off', color: 'info', text: t('dayPanel.findings.noShift') })
  if (x.reconciliation === 'ambiguous') f.push({ icon: 'tabler-help-circle', color: 'warning', text: t('dayPanel.findings.ambiguous') })
  if (x.incomplete) f.push({ icon: 'tabler-alert-triangle', color: 'warning', text: t('dayPanel.findings.incomplete') })
  // Écart analysé seulement sur un pointage TERMINÉ (actual_end présent) : en cours ⇒ réalisé partiel.
  if (x.gap_minutes !== null && x.gap_minutes !== undefined && x.actual_end && Math.abs(x.gap_minutes) >= 15)
    f.push({ icon: 'tabler-arrows-diff', color: x.gap_minutes < 0 ? 'error' : 'success', text: t('dayPanel.findings.gap', { min: Math.abs(x.gap_minutes) }) })
  if (!f.length) f.push({ icon: 'tabler-circle-check', color: 'success', text: t('dayPanel.findings.ok') })

  return f
})

// ── Édition de créneau DEPUIS le panneau (réutilise updateShift ; brouillon only — I9 immutabilité) ──
const editingShift = ref(null)
const shiftForm = ref({ start: '', end: '' })
const savingShift = ref(false)
const toHM = iso => { const m = String(iso || '').match(/[T\s](\d{2}:\d{2})/); return m ? m[1] : '' }
const openShiftEdit = s => {
  if (!props.canPlanningManage) return
  if (s.status !== 'draft') { toast(t('dayPanel.shiftImmutable'), 'info'); return }
  editingShift.value = s
  shiftForm.value = { start: toHM(s.start_at), end: toHM(s.end_at) }
}
const saveShift = async () => {
  savingShift.value = true
  try {
    await planningStore.updateShift(editingShift.value.id, {
      start_at: `${props.date}T${shiftForm.value.start}:00`,
      end_at: `${props.date}T${shiftForm.value.end}:00`,
      expected_updated_at: editingShift.value.updated_at,
    })
    toast(t('dayPanel.shiftSaved'), 'success')
    editingShift.value = null
    await loadDetail()
    emit('corrected') // rafraîchit aussi le board prévu/réel
  } catch (e) {
    toast(e.response?.data?.message || t('common.error'), 'error')
  } finally { savingShift.value = false }
}

// ── Correction (workflow tracé existant) ──
const correctOpen = ref(false)
const correctForm = ref({ clock_in: '', clock_out: '', reason: '' })
const correcting = ref(false)
const canCorrect = computed(() => props.canManage && !!r.value.time_entry_id)
const openCorrect = () => {
  correctForm.value = {
    clock_in: r.value.actual_start ? r.value.actual_start.slice(0, 16) : '',
    clock_out: r.value.actual_end ? r.value.actual_end.slice(0, 16) : '',
    reason: '',
  }
  correctOpen.value = true
}
const submitCorrect = async () => {
  if (!correctForm.value.reason) return
  correcting.value = true
  try {
    await store.correctTimeEntry(r.value.time_entry_id, {
      clockIn: correctForm.value.clock_in ? correctForm.value.clock_in + ':00' : undefined,
      clockOut: correctForm.value.clock_out ? correctForm.value.clock_out + ':00' : undefined,
      reason: correctForm.value.reason,
    })
    toast(t('timeCockpit.correctionSaved'), 'success')
    correctOpen.value = false
    emit('corrected')
  } catch (e) {
    toast(e.response?.data?.message || t('common.error'), 'error')
  } finally {
    correcting.value = false
  }
}
</script>

<template>
  <VNavigationDrawer class="wf-scroll-drawer" v-model="isOpen" temporary location="end" width="460" :scrim="true">
    <div v-if="row" class="d-flex flex-column h-100">
      <!-- En-tête : collaborateur + date -->
      <div class="d-flex align-center justify-space-between pa-4 border-b">
        <div class="d-flex align-center gap-3">
          <VAvatar color="primary" variant="tonal" rounded size="40"><VIcon icon="tabler-user" /></VAvatar>
          <div>
            <div class="text-subtitle-1 font-weight-medium">{{ r.employee_name || '—' }}</div>
            <div class="text-caption text-medium-emphasis">{{ new Date(date).toLocaleDateString(locale) }}</div>
          </div>
        </div>
        <div class="d-flex align-center gap-2">
          <VChip :color="statusColor(r.status)" size="small" label>{{ t(`timeCockpit.status.${r.status}`, r.status) }}</VChip>
          <VBtn icon variant="text" size="small" @click="isOpen = false"><VIcon icon="tabler-x" /></VBtn>
        </div>
      </div>

      <div class="pa-4 flex-grow-1 overflow-y-auto">
        <!-- Prévu vs Réel -->
        <div class="d-flex gap-3 mb-4">
          <VCard variant="tonal" class="flex-grow-1" color="primary">
            <VCardText class="py-3">
              <div class="text-overline mb-1">{{ t('timeCockpit.col.planned') }}</div>
              <div class="text-h6">{{ fmtTime(r.planned_start) ? `${fmtTime(r.planned_start)} – ${fmtTime(r.planned_end)}` : '—' }}</div>
              <div class="text-caption">{{ t('dayPanel.plannedDuration') }}: {{ fmtMin(r.planned_minutes) }}</div>
            </VCardText>
          </VCard>
          <VCard variant="tonal" class="flex-grow-1" :color="r.actual_start ? 'success' : 'secondary'">
            <VCardText class="py-3">
              <div class="text-overline mb-1">{{ t('timeCockpit.col.actual') }}</div>
              <div class="text-h6">
                <span v-if="r.actual_start">{{ fmtTime(r.actual_start) }} – {{ fmtTime(r.actual_end) || t('timeCockpit.notClockedOut') }}</span>
                <span v-else>{{ t('dayPanel.notClocked') }}</span>
              </div>
              <div class="text-caption">{{ t('dayPanel.worked') }}: {{ fmtMin(r.worked_minutes) }} · {{ t('dayPanel.breaks') }}: {{ fmtMin(r.break_minutes) }}</div>
            </VCardText>
          </VCard>
        </div>

        <!-- Analyse -->
        <div class="text-overline text-medium-emphasis mb-2">{{ t('dayPanel.analysis') }}</div>
        <div class="d-flex align-center gap-2 mb-2">
          <VChip :color="reconColor(r.reconciliation)" size="small" variant="tonal">{{ reconLabel(r.reconciliation) }}</VChip>
        </div>
        <VList density="compact" class="mb-2">
          <VListItem v-for="(f, i) in findings" :key="i" :title="f.text">
            <template #prepend><VIcon :icon="f.icon" :color="f.color" size="20" class="me-2" /></template>
          </VListItem>
        </VList>

        <VDivider class="my-3" />

        <!-- Détail (créneaux + pointages de la journée) -->
        <div class="text-overline text-medium-emphasis mb-2">{{ t('timeWork.tabs.planning') }}</div>
        <div v-if="loadingDetail" class="text-center py-3"><VProgressCircular indeterminate size="22" color="primary" /></div>
        <template v-else>
          <div v-if="dayShifts.length" class="mb-3">
            <VChip
              v-for="s in dayShifts"
              :key="s.id"
              class="me-1 mb-1"
              size="small"
              variant="outlined"
              :color="s.status === 'published' ? 'success' : 'secondary'"
              :append-icon="canPlanningManage ? (s.status === 'draft' ? 'tabler-edit' : 'tabler-lock') : undefined"
              :style="canPlanningManage && s.status === 'draft' ? 'cursor:pointer' : ''"
              data-test="day-shift-chip"
              @click="openShiftEdit(s)"
            >
              {{ fmtTime(s.start_at) }}–{{ fmtTime(s.end_at) }} · {{ shiftStatusLabel(s.status) }}
            </VChip>
            <!-- Édition inline d'un créneau brouillon (réutilise le service updateShift) -->
            <VExpandTransition>
              <VCard v-if="editingShift" variant="tonal" class="mt-2">
                <VCardText class="py-3">
                  <div class="text-caption text-medium-emphasis mb-2">{{ t('dayPanel.editShift') }}</div>
                  <div class="d-flex gap-2">
                    <AppTextField v-model="shiftForm.start" type="time" :label="t('employeeFile.start')" density="compact" data-test="day-shift-start" />
                    <AppTextField v-model="shiftForm.end" type="time" :label="t('employeeFile.end')" density="compact" data-test="day-shift-end" />
                  </div>
                  <div class="d-flex gap-2 justify-end mt-2">
                    <VBtn variant="outlined" size="small" @click="editingShift = null">{{ t('common.cancel') }}</VBtn>
                    <VBtn color="primary" size="small" :loading="savingShift" :disabled="!shiftForm.start || !shiftForm.end" data-test="day-shift-save" @click="saveShift">{{ t('common.save') }}</VBtn>
                  </div>
                </VCardText>
              </VCard>
            </VExpandTransition>
          </div>
          <div v-else class="text-caption text-disabled mb-3">{{ t('dayPanel.noShift') }}</div>

          <div class="text-overline text-medium-emphasis mb-2">{{ t('timeWork.tabs.presence') }}</div>
          <div v-if="dayEntries.length">
            <div v-for="e in dayEntries" :key="e.id" class="text-body-2 mb-1">
              {{ fmtTime(e.clock_in) }} – {{ fmtTime(e.clock_out) || '…' }}
              <span class="text-medium-emphasis">· {{ t(`timeCockpit.status.${e.status}`, e.status) }}</span>
            </div>
          </div>
          <div v-else class="text-caption text-disabled">{{ t('dayPanel.noEntry') }}</div>
        </template>

        <!-- Correction (si autorisée + pointage existant) -->
        <template v-if="canCorrect">
          <VDivider class="my-3" />
          <VExpandTransition>
            <div v-if="correctOpen">
              <div class="text-overline text-medium-emphasis mb-2">{{ t('timeCockpit.correctTitle') }}</div>
              <AppTextField v-model="correctForm.clock_in" type="datetime-local" :label="t('timeCockpit.clockIn')" density="compact" class="mb-2" />
              <AppTextField v-model="correctForm.clock_out" type="datetime-local" :label="t('timeCockpit.clockOut')" density="compact" class="mb-2" />
              <AppTextarea v-model="correctForm.reason" :label="t('timeCockpit.reason') + ' *'" rows="2" class="mb-2" />
              <div class="d-flex gap-2 justify-end">
                <VBtn variant="outlined" size="small" @click="correctOpen = false">{{ t('common.cancel') }}</VBtn>
                <VBtn color="primary" size="small" :loading="correcting" :disabled="!correctForm.reason" data-test="day-correct-submit" @click="submitCorrect">{{ t('common.save') }}</VBtn>
              </div>
            </div>
          </VExpandTransition>
        </template>
      </div>

      <!-- Actions contextuelles -->
      <div class="pa-4 border-t d-flex flex-column gap-2">
        <VBtn
          v-if="canCorrect && !correctOpen"
          color="primary"
          variant="tonal"
          prepend-icon="tabler-edit"
          data-test="day-correct"
          @click="openCorrect"
        >
          {{ t('dayPanel.correct') }}
        </VBtn>
        <div class="d-flex gap-2">
          <VBtn class="flex-grow-1" variant="outlined" size="small" prepend-icon="tabler-calendar" @click="emit('go-planning', row)">{{ t('timeWork.tabs.planning') }}</VBtn>
          <VBtn class="flex-grow-1" variant="outlined" size="small" prepend-icon="tabler-clock" @click="emit('go-presence', row)">{{ t('timeWork.tabs.presence') }}</VBtn>
        </div>
        <VBtn variant="text" size="small" prepend-icon="tabler-user-circle" data-test="day-open-fiche" @click="emit('open-fiche', row)">{{ t('workforceDocuments.detail.openFiche') }}</VBtn>
      </div>
    </div>
  </VNavigationDrawer>
</template>
