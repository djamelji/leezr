<script setup>
// Smart Planning — planning journalier détaillé d'un shift : TIMELINE visuelle (axe horaire, blocs
// proportionnels, pauses, plages libres) + ajout/édition/duplication/annulation. Écritures persistées via
// le store. Ne mute jamais le shift ni le TimeEntry.
import { usePlanningStore } from '@/modules/company/workforce/planning.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  isDialogVisible: { type: Boolean, default: false },
  shift: { type: Object, default: null },
  employeeName: { type: String, default: '' },
})
const emit = defineEmits(['update:isDialogVisible', 'changed'])

const { t, locale } = useI18n()
const { toast } = useAppToast()
const store = usePlanningStore()

const activities = ref([])
const loading = ref(false)
const editingId = ref(null)
const showForm = ref(false)
const form = ref({ activity_type: '', label: '', start: '', end: '', work_location_id: null, priority: 'normal' })

const locationItems = computed(() => store.allLocations.map(l => ({ title: l.name, value: l.id })))
const priorityItems = computed(() => ['low', 'normal', 'high', 'critical'].map(p => ({ title: t(`planning.coverage.priorities.${p}`), value: p })))

const timeOfDay = dt => {
  if (!dt) return ''
  const m = String(dt).match(/[T ](\d{2}):(\d{2})/)

  return m ? `${m[1]}:${m[2]}` : ''
}
const toTs = dt => new Date(String(dt).replace(' ', 'T')).getTime()

const shiftStartTs = computed(() => props.shift ? toTs(props.shift.start_at) : 0)
const shiftEndTs = computed(() => props.shift ? toTs(props.shift.end_at) : 0)
const totalMs = computed(() => Math.max(1, shiftEndTs.value - shiftStartTs.value))
const shiftRange = computed(() => props.shift ? `${timeOfDay(props.shift.start_at)} – ${timeOfDay(props.shift.end_at)}` : '')
const isPublished = computed(() => props.shift?.status === 'published')

const pct = ts => Math.max(0, Math.min(100, ((ts - shiftStartTs.value) / totalMs.value) * 100))
const activityColor = a => ({ coupe: '#4b4bd6', coloration: '#b5761b', balayage: '#0ea5a5', formation: '#1f8a54' }[a?.activity_type] || '#4b4bd6')

// Blocs d'activités positionnés proportionnellement.
const blocks = computed(() => activities.value.filter(a => a.status === 'planned').map(a => {
  const s = toTs(a.start_at)
  const e = toTs(a.end_at)

  return { a, top: pct(s), height: Math.max(3.5, pct(e) - pct(s)), color: activityColor(a), label: a.label || a.activity_type || t('planning.activities.untitled'), time: `${timeOfDay(a.start_at)}–${timeOfDay(a.end_at)}` }
}))

// Fenêtres de pause (si déclarées sur le shift).
const breakBlocks = computed(() => {
  const w = props.shift?.template_snapshot?.break_windows
  if (!Array.isArray(w) || !props.shift) return []
  const day = String(props.shift.start_at).slice(0, 10)

  return w.filter(b => b.start && b.end).map(b => {
    const s = toTs(`${day}T${b.start}:00`)
    let e = toTs(`${day}T${b.end}:00`)
    if (e <= s) e += 864e5

    return { top: pct(s), height: Math.max(2, pct(e) - pct(s)), time: `${b.start}–${b.end}` }
  })
})

// Graduations horaires.
const hourTicks = computed(() => {
  if (!props.shift) return []
  const ticks = []
  const start = new Date(shiftStartTs.value)
  const d = new Date(start)
  d.setMinutes(0, 0, 0)
  if (d.getTime() < shiftStartTs.value) d.setHours(d.getHours() + 1)
  for (let ts = d.getTime(); ts <= shiftEndTs.value; ts += 36e5) {
    ticks.push({ top: pct(ts), label: new Date(ts).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) })
  }

  return ticks
})

const fmt = iso => iso ? timeOfDay(iso) : '—'

const resetForm = () => {
  editingId.value = null
  showForm.value = false
  form.value = { activity_type: '', label: '', start: '', end: '', work_location_id: null, priority: 'normal' }
}

const load = async () => {
  if (!props.shift) return
  loading.value = true
  try {
    activities.value = await store.fetchActivities({ shiftId: props.shift.id })
  } finally {
    loading.value = false
  }
}

watch(() => props.isDialogVisible, v => { if (v) { resetForm(); load() } })

const timeToIso = (hhmm, afterIso = null) => {
  if (!hhmm || !props.shift) return null
  const day = String(props.shift.start_at).slice(0, 10)
  let iso = `${day}T${hhmm}:00`
  if (afterIso && new Date(iso) <= new Date(afterIso)) {
    const d = new Date(`${day}T00:00:00`)
    d.setDate(d.getDate() + 1)
    iso = `${d.toISOString().slice(0, 10)}T${hhmm}:00`
  }

  return iso
}

// Préremplit les heures DANS les bornes du shift (évite les 422 « hors bornes » au clic d'ajout).
const addMinutes = (hhmm, mins) => {
  const [h, m] = hhmm.split(':').map(Number)
  const d = h * 60 + m + mins
  const hh = String(Math.floor((d % 1440) / 60)).padStart(2, '0')
  const mm = String(d % 60).padStart(2, '0')

  return `${hh}:${mm}`
}
const openAdd = () => {
  resetForm()
  showForm.value = true
  const shiftStart = timeOfDay(props.shift?.start_at)
  const shiftEnd = timeOfDay(props.shift?.end_at)
  if (!shiftStart) return
  // Premier créneau LIBRE : après la dernière activité planifiée (évite un chevauchement au clic d'ajout).
  let start = shiftStart
  const ends = activities.value.filter(a => a.status === 'planned').map(a => timeOfDay(a.end_at)).filter(Boolean)
  if (ends.length) {
    const lastEnd = ends.sort().at(-1)
    if (lastEnd > start) start = lastEnd
  }
  if (shiftEnd && start >= shiftEnd) start = shiftStart // shift plein → laisser l'utilisateur ajuster
  form.value.start = start
  form.value.end = addMinutes(start, 60)
  if (shiftEnd && form.value.end > shiftEnd) form.value.end = shiftEnd
}

const submit = async () => {
  if (!form.value.start || !form.value.end) { toast(t('validation.required'), 'error'); return }
  const startIso = timeToIso(form.value.start)
  const endIso = timeToIso(form.value.end, startIso)
  const payload = {
    shift_id: props.shift.id, start_at: startIso, end_at: endIso,
    activity_type: form.value.activity_type || null, label: form.value.label || null,
    work_location_id: form.value.work_location_id, priority: form.value.priority,
  }
  try {
    const resp = editingId.value
      ? await store.updateActivity(editingId.value, payload)
      : await store.createActivity(payload)
    if (resp?.warnings?.length) toast(t('planning.activities.warnings.travel'), 'warning')
    else toast(editingId.value ? t('planning.activities.updated') : t('planning.activities.created'), 'success')
    resetForm()
    await load()
    emit('changed')
  } catch (error) {
    toast(activityError(error), 'error')
  }
}

// Messages d'erreur explicites (plutôt qu'un « Conflit » générique).
const activityError = error => {
  const msg = (error.data?.message || error.response?._data?.message) || ''
  if (/within the shift boundaries/i.test(msg)) return t('planning.activities.errors.bounds')
  if (/overlaps a planned break/i.test(msg)) return t('planning.activities.errors.break')
  if (/overlaps another activity/i.test(msg)) return t('planning.activities.errors.overlap')
  if (/exceed the shift workable time/i.test(msg)) return t('planning.activities.errors.capacity')
  if (/end must be after/i.test(msg)) return t('planning.activities.errors.order')

  return msg || t('planning.activities.conflict')
}

const edit = a => {
  editingId.value = a.id
  showForm.value = true
  form.value = {
    activity_type: a.activity_type || '', label: a.label || '',
    start: fmt(a.start_at), end: fmt(a.end_at), work_location_id: a.work_location_id || null, priority: a.priority || 'normal',
  }
}
const duplicate = async a => { try { await store.duplicateActivity(a.id); toast(t('planning.activities.duplicated'), 'success'); await load(); emit('changed') } catch (e) { toast((e.data?.message || e.response?._data?.message) || t('planning.activities.conflict'), 'error') } }
const cancelActivity = async a => { try { await store.cancelActivity(a.id); toast(t('planning.activities.cancelled'), 'success'); await load(); emit('changed') } catch (e) { toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error') } }
const removeActivity = async a => { try { await store.deleteActivity(a.id); toast(t('planning.activities.deleted'), 'success'); await load(); emit('changed') } catch (e) { toast((e.data?.message || e.response?._data?.message) || t('planning.activities.deleteBlocked'), 'error') } }
</script>

<template>
  <VDialog
    :model-value="isDialogVisible"
    max-width="760"
    @update:model-value="emit('update:isDialogVisible', $event)"
  >
    <VCard v-if="shift">
      <VCardText class="d-flex align-center justify-space-between pb-3">
        <div class="d-flex align-center gap-3">
          <VAvatar color="primary" variant="tonal" size="40">
            <span class="text-sm font-weight-medium">{{ (employeeName || '?').split(' ').map(p => p[0]).join('').slice(0, 2) }}</span>
          </VAvatar>
          <div>
            <h5 class="text-h5 mb-0">{{ employeeName }}</h5>
            <div class="text-body-2 text-medium-emphasis d-flex align-center gap-2">
              <VIcon icon="tabler-clock" size="14" /> {{ shiftRange }}
              <VChip size="x-small" :color="isPublished ? 'success' : 'warning'" variant="tonal">
                {{ isPublished ? $t('workforce.statuses.shift.published', 'Publié') : $t('workforce.statuses.shift.draft', 'Brouillon') }}
              </VChip>
            </div>
          </div>
        </div>
        <VBtn icon variant="text" size="small" @click="emit('update:isDialogVisible', false)">
          <VIcon icon="tabler-x" />
        </VBtn>
      </VCardText>
      <VDivider />

      <VCardText>
        <VAlert
          v-if="isPublished"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4 text-caption"
        >
          {{ $t('planning.activities.publishedHint') }}
        </VAlert>

        <VRow>
          <!-- Timeline visuelle -->
          <VCol cols="12" md="7">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-body-2 font-weight-medium">{{ $t('planning.activities.timeline') }}</span>
              <VBtn
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="tabler-plus"
                data-test="activity-add"
                @click="openAdd"
              >
                {{ $t('planning.activities.addActivity') }}
              </VBtn>
            </div>

            <VProgressLinear v-if="loading" indeterminate color="primary" class="mb-2" />
            <div class="act-timeline">
              <div class="act-timeline__axis">
                <div
                  v-for="(tk, i) in hourTicks"
                  :key="i"
                  class="act-timeline__tick"
                  :style="{ top: tk.top + '%' }"
                >{{ tk.label }}</div>
              </div>
              <div class="act-timeline__track">
                <div
                  v-for="(tk, i) in hourTicks"
                  :key="`g${i}`"
                  class="act-timeline__grid"
                  :style="{ top: tk.top + '%' }"
                />
                <!-- Pauses -->
                <div
                  v-for="(b, i) in breakBlocks"
                  :key="`b${i}`"
                  class="act-timeline__break"
                  :style="{ top: b.top + '%', height: b.height + '%' }"
                  :title="$t('planning.templates.fields.breakDuration') + ' ' + b.time"
                >
                  <VIcon icon="tabler-coffee" size="12" /> {{ b.time }}
                </div>
                <!-- Activités -->
                <div
                  v-for="blk in blocks"
                  :key="blk.a.id"
                  class="act-timeline__block"
                  :style="{ top: blk.top + '%', height: blk.height + '%', '--ac': blk.color }"
                >
                  <div class="act-timeline__block-inner">
                    <div class="act-timeline__block-main">
                      <span class="act-timeline__block-label">{{ blk.label }}</span>
                      <span class="act-timeline__block-time">{{ blk.time }}</span>
                    </div>
                    <div class="act-timeline__block-actions">
                      <VBtn icon variant="text" size="x-small" :title="$t('common.edit')" @click="edit(blk.a)"><VIcon icon="tabler-edit" size="14" /></VBtn>
                      <VBtn icon variant="text" size="x-small" :title="$t('planning.activities.duplicate')" @click="duplicate(blk.a)"><VIcon icon="tabler-copy" size="14" /></VBtn>
                      <VBtn icon variant="text" size="x-small" color="warning" :title="$t('common.cancel')" @click="cancelActivity(blk.a)"><VIcon icon="tabler-ban" size="14" /></VBtn>
                      <VBtn icon variant="text" size="x-small" color="error" :title="$t('common.delete')" @click="removeActivity(blk.a)"><VIcon icon="tabler-trash" size="14" /></VBtn>
                    </div>
                  </div>
                </div>
                <div v-if="!loading && !blocks.length" class="act-timeline__empty">
                  <VIcon icon="tabler-timeline-event-plus" size="28" class="mb-2 text-disabled" />
                  <div class="text-body-2 text-medium-emphasis">{{ $t('planning.activities.empty') }}</div>
                </div>
              </div>
            </div>
          </VCol>

          <!-- Formulaire repliable (secondaire) -->
          <VCol cols="12" md="5">
            <VExpandTransition>
              <div v-if="showForm">
                <div class="text-body-2 font-weight-medium mb-2">
                  {{ editingId ? $t('planning.activities.editActivity') : $t('planning.activities.addActivity') }}
                </div>
                <AppTextField v-model="form.label" :label="$t('planning.activities.fields.label')" density="compact" class="mb-2" />
                <AppTextField v-model="form.activity_type" :label="$t('planning.templates.fields.activityType')" density="compact" class="mb-2" />
                <div class="d-flex gap-2 mb-2">
                  <AppTextField v-model="form.start" :label="$t('planning.activities.fields.start')" type="time" density="compact" />
                  <AppTextField v-model="form.end" :label="$t('planning.activities.fields.end')" type="time" density="compact" />
                </div>
                <AppSelect v-model="form.work_location_id" :items="locationItems" :label="$t('planning.templates.fields.location')" density="compact" clearable class="mb-2" />
                <AppSelect v-model="form.priority" :items="priorityItems" :label="$t('planning.coverage.fields.priority')" density="compact" class="mb-3" />
                <div class="d-flex gap-2 justify-end">
                  <VBtn variant="outlined" size="small" @click="resetForm">{{ $t('common.cancel') }}</VBtn>
                  <VBtn color="primary" size="small" data-test="activity-submit" @click="submit">{{ editingId ? $t('common.save') : $t('common.add') }}</VBtn>
                </div>
              </div>
              <div v-else class="act-hint text-body-2 text-medium-emphasis">
                <VIcon icon="tabler-info-circle" size="16" class="me-1" />
                {{ $t('planning.activities.formHint') }}
              </div>
            </VExpandTransition>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </VDialog>
</template>

<style lang="scss" scoped>
.act-timeline {
  display: flex;
  gap: 8px;
  block-size: 380px;

  &__axis {
    position: relative;
    flex: 0 0 44px;
    font-size: 0.68rem;
    color: rgba(var(--v-theme-on-surface), 0.6);
    font-variant-numeric: tabular-nums;
  }
  &__tick { position: absolute; inset-inline-end: 0; transform: translateY(-50%); }

  &__track {
    position: relative;
    flex: 1 1 auto;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 8px;
    background: rgba(var(--v-theme-on-surface), 0.015);
    overflow: hidden;
  }
  &__grid { position: absolute; inset-inline: 0; block-size: 0; border-block-start: 1px dashed rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.7)); }

  &__break {
    position: absolute;
    inset-inline: 2px;
    display: flex;
    align-items: center;
    gap: 4px;
    padding-inline: 6px;
    background: repeating-linear-gradient(45deg, rgba(var(--v-theme-on-surface), 0.05), rgba(var(--v-theme-on-surface), 0.05) 6px, transparent 6px, transparent 12px);
    border-radius: 4px;
    font-size: 0.62rem;
    color: rgba(var(--v-theme-on-surface), 0.6);
  }

  &__block {
    position: absolute;
    inset-inline: 4px;
    border-radius: 6px;
    border-inline-start: 3px solid var(--ac);
    background: color-mix(in srgb, var(--ac) 16%, rgb(var(--v-theme-surface)));
    overflow: hidden;
  }
  &__block-inner { display: flex; align-items: flex-start; justify-content: space-between; block-size: 100%; padding: 3px 5px; }
  &__block-main { min-inline-size: 0; }
  &__block-label { display: block; font-size: 0.76rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  &__block-time { font-size: 0.66rem; color: rgba(var(--v-theme-on-surface), 0.6); font-variant-numeric: tabular-nums; }
  &__block-actions { display: flex; flex-shrink: 0; opacity: 0; transition: opacity 0.12s ease; }
  &__block:hover &__block-actions { opacity: 1; }

  &__empty { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
}
.act-hint { padding: 12px; border: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; }
</style>
