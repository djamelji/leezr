<script setup>
// Smart Planning SP1b — planning journalier détaillé d'un shift : timeline des activités successives,
// plages libres, ajout rapide, modification de durée, duplication, annulation/suppression, alertes de conflit.
// Écritures RÉELLEMENT persistées via le store (API serveur). Ne mute jamais le shift ni le TimeEntry.
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
const form = ref({ activity_type: '', label: '', start: '', end: '', work_location_id: null, priority: 'normal' })

const locationItems = computed(() => store.allLocations.map(l => ({ title: l.name, value: l.id })))
const priorityItems = computed(() => ['low', 'normal', 'high', 'critical'].map(p => ({ title: t(`planning.coverage.priorities.${p}`), value: p })))

const shiftDate = computed(() => (props.shift?.start_at || '').slice(0, 10))
const fmt = iso => iso ? new Date(iso).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) : '—'
const shiftRange = computed(() => props.shift ? `${fmt(props.shift.start_at)} – ${fmt(props.shift.end_at)}` : '')
const isPublished = computed(() => props.shift?.status === 'published')

const timeToIso = (hhmm, afterIso = null) => {
  if (!hhmm) return null
  let iso = `${shiftDate.value}T${hhmm}:00`
  // Passage de minuit : si une borne de référence existe et que l'heure est antérieure, +1 jour.
  if (afterIso && new Date(iso) <= new Date(afterIso)) {
    const d = new Date(`${shiftDate.value}T00:00:00`)
    d.setDate(d.getDate() + 1)
    iso = `${d.toISOString().slice(0, 10)}T${hhmm}:00`
  }

  return iso
}

const resetForm = () => {
  editingId.value = null
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

const submit = async () => {
  if (!form.value.start || !form.value.end) {
    toast(t('validation.required'), 'error')

    return
  }
  const startIso = timeToIso(form.value.start)
  const endIso = timeToIso(form.value.end, startIso)
  const payload = {
    shift_id: props.shift.id,
    start_at: startIso,
    end_at: endIso,
    activity_type: form.value.activity_type || null,
    label: form.value.label || null,
    work_location_id: form.value.work_location_id,
    priority: form.value.priority,
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
    toast(error.response?.data?.message || t('planning.activities.conflict'), 'error')
  }
}

const edit = a => {
  editingId.value = a.id
  form.value = {
    activity_type: a.activity_type || '', label: a.label || '',
    start: fmt(a.start_at), end: fmt(a.end_at),
    work_location_id: a.work_location_id || null, priority: a.priority || 'normal',
  }
}

const duplicate = async a => {
  try {
    await store.duplicateActivity(a.id)
    toast(t('planning.activities.duplicated'), 'success')
    await load()
    emit('changed')
  } catch (error) {
    toast(error.response?.data?.message || t('planning.activities.conflict'), 'error')
  }
}

const cancelActivity = async a => {
  try {
    await store.cancelActivity(a.id)
    toast(t('planning.activities.cancelled'), 'success')
    await load()
    emit('changed')
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  }
}

const removeActivity = async a => {
  try {
    await store.deleteActivity(a.id)
    toast(t('planning.activities.deleted'), 'success')
    await load()
    emit('changed')
  } catch (error) {
    toast(error.response?.data?.message || t('planning.activities.deleteBlocked'), 'error')
  }
}

const priorityColor = p => ({ low: 'secondary', normal: 'primary', high: 'warning', critical: 'error' }[p] || 'primary')
</script>

<template>
  <VDialog
    :model-value="isDialogVisible"
    max-width="620"
    @update:model-value="emit('update:isDialogVisible', $event)"
  >
    <VCard v-if="shift">
      <VCardText class="d-flex align-center justify-space-between pb-2">
        <div>
          <h5 class="text-h5">
            {{ employeeName }}
          </h5>
          <div class="text-body-2 text-medium-emphasis">
            {{ shiftRange }}
            <VChip
              size="x-small"
              :color="isPublished ? 'success' : 'default'"
              variant="tonal"
              class="ms-2"
            >
              {{ isPublished ? $t('workforce.statuses.shift.published', 'Publié') : $t('workforce.statuses.shift.draft', 'Brouillon') }}
            </VChip>
          </div>
        </div>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="emit('update:isDialogVisible', false)"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </VCardText>

      <VDivider />

      <VCardText>
        <div
          v-if="isPublished"
          class="text-caption text-medium-emphasis mb-3"
        >
          <VIcon
            icon="tabler-info-circle"
            size="14"
          /> {{ $t('planning.activities.publishedHint') }}
        </div>

        <!-- Timeline -->
        <VProgressLinear
          v-if="loading"
          indeterminate
          color="primary"
          class="mb-3"
        />
        <div
          v-else-if="!activities.length"
          class="text-body-2 text-medium-emphasis mb-3"
        >
          {{ $t('planning.activities.empty') }}
        </div>
        <VList
          v-else
          class="mb-2"
          density="compact"
        >
          <VListItem
            v-for="a in activities"
            :key="a.id"
            :class="{ 'text-decoration-line-through text-disabled': a.status === 'cancelled' }"
          >
            <template #prepend>
              <VChip
                size="x-small"
                :color="priorityColor(a.priority)"
                variant="tonal"
                class="me-2"
                style="min-inline-size: 92px;"
              >
                {{ fmt(a.start_at) }}–{{ fmt(a.end_at) }}
              </VChip>
            </template>
            <VListItemTitle>
              {{ a.label || a.activity_type || $t('planning.activities.untitled') }}
              <span class="text-caption text-medium-emphasis">· {{ a.duration_minutes }} min</span>
            </VListItemTitle>
            <template #append>
              <VBtn
                icon
                variant="text"
                size="x-small"
                :title="$t('common.edit')"
                @click="edit(a)"
              >
                <VIcon
                  icon="tabler-edit"
                  size="16"
                />
              </VBtn>
              <VBtn
                icon
                variant="text"
                size="x-small"
                :title="$t('planning.activities.duplicate')"
                @click="duplicate(a)"
              >
                <VIcon
                  icon="tabler-copy"
                  size="16"
                />
              </VBtn>
              <VBtn
                v-if="a.status !== 'cancelled'"
                icon
                variant="text"
                size="x-small"
                color="warning"
                :title="$t('common.cancel')"
                @click="cancelActivity(a)"
              >
                <VIcon
                  icon="tabler-ban"
                  size="16"
                />
              </VBtn>
              <VBtn
                icon
                variant="text"
                size="x-small"
                color="error"
                :title="$t('common.delete')"
                @click="removeActivity(a)"
              >
                <VIcon
                  icon="tabler-trash"
                  size="16"
                />
              </VBtn>
            </template>
          </VListItem>
        </VList>

        <VDivider class="my-3" />

        <!-- Add / edit form -->
        <div class="text-body-2 font-weight-medium mb-2">
          {{ editingId ? $t('planning.activities.editActivity') : $t('planning.activities.addActivity') }}
        </div>
        <VRow>
          <VCol cols="6">
            <AppTextField
              v-model="form.label"
              :label="$t('planning.activities.fields.label')"
              density="compact"
            />
          </VCol>
          <VCol cols="6">
            <AppTextField
              v-model="form.activity_type"
              :label="$t('planning.templates.fields.activityType')"
              density="compact"
            />
          </VCol>
          <VCol cols="6">
            <AppTextField
              v-model="form.start"
              :label="$t('planning.activities.fields.start')"
              type="time"
              density="compact"
            />
          </VCol>
          <VCol cols="6">
            <AppTextField
              v-model="form.end"
              :label="$t('planning.activities.fields.end')"
              type="time"
              density="compact"
            />
          </VCol>
          <VCol cols="6">
            <AppSelect
              v-model="form.work_location_id"
              :items="locationItems"
              :label="$t('planning.templates.fields.location')"
              density="compact"
              clearable
            />
          </VCol>
          <VCol cols="6">
            <AppSelect
              v-model="form.priority"
              :items="priorityItems"
              :label="$t('planning.coverage.fields.priority')"
              density="compact"
            />
          </VCol>
        </VRow>
        <div class="d-flex justify-end gap-2 mt-2">
          <VBtn
            v-if="editingId"
            variant="outlined"
            size="small"
            @click="resetForm"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            size="small"
            data-test="activity-submit"
            @click="submit"
          >
            {{ editingId ? $t('common.save') : $t('common.add') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>
  </VDialog>
</template>
