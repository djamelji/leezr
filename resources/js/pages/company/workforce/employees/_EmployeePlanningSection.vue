<script setup>
// ADR-640 (W-C) — shifts à venir du collaborateur (lazy).
// W-HR-UX-5 (P4) — action CONTEXTUELLE : programmer (et publier) un créneau pour CE collaborateur
// directement depuis sa fiche, sans aller chercher la création dans la grille planning.
import { $api } from '@/utils/api'
import { formatDate, formatDateTime } from '@/utils/datetime'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  employeeId: { type: [String, Number], required: true },
  canManage: { type: Boolean, default: false },
})
const { t } = useI18n()
const router = useRouter()
const { toast } = useAppToast()
const loading = ref(true)
const shifts = ref([])

const load = async () => {
  loading.value = true
  try {
    const d = await $api(`/workforce/planning/shifts?employee_id=${props.employeeId}&per_page=10`)
    shifts.value = d.data ?? d ?? []
  } catch { /* */ } finally { loading.value = false }
}
onMounted(load)

// ── Création contextuelle d'un créneau ───────────────────────
const dialog = ref(false)
const saving = ref(false)
const form = ref({ date: '', start: '09:00', end: '17:00', publish: true })

const openCreate = () => {
  form.value = { date: new Date(Date.now() + 86400000).toISOString().slice(0, 10), start: '09:00', end: '17:00', publish: true }
  dialog.value = true
}

const submit = async () => {
  saving.value = true
  try {
    const created = await $api('/workforce/planning/shifts', {
      method: 'POST',
      body: {
        employee_id: Number(props.employeeId),
        date: form.value.date,
        start_at: `${form.value.date}T${form.value.start}:00`,
        end_at: `${form.value.date}T${form.value.end}:00`,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      },
    })
    const shiftId = created?.id ?? created?.data?.id
    if (form.value.publish && shiftId)
      await $api('/workforce/planning/shifts/publish', { method: 'POST', body: { shift_ids: [shiftId] } })

    toast(t('employeeFile.shiftCreated'), 'success')
    dialog.value = false
    await load()
  } catch (e) {
    toast(e?.data?.message || t('common.error'), 'error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div v-if="canManage" class="d-flex justify-end mb-3">
      <VBtn size="small" color="primary" prepend-icon="tabler-calendar-plus" data-test="fiche-shift-add" @click="openCreate">
        {{ t('employeeFile.scheduleShift') }}
      </VBtn>
    </div>

    <VSkeletonLoader v-if="loading" type="table-row@3" />
    <template v-else>
      <VTable v-if="shifts.length" density="compact">
        <thead><tr><th>{{ t('employeeFile.date') }}</th><th>{{ t('employeeFile.hours') }}</th><th>{{ t('employeeFile.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in shifts" :key="s.id">
            <td>{{ formatDate(s.date) }}</td>
            <td>{{ s.start_at ? formatDateTime(s.start_at) : '—' }}</td>
            <td><VChip size="x-small">{{ s.status }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
      <div v-else class="text-center text-disabled py-4">{{ t('employeeFile.noPlanning') }}</div>
      <VBtn class="mt-3" variant="text" size="small" append-icon="tabler-arrow-right" @click="router.push('/company/workforce/planning')">{{ t('employeeFile.seeAll') }}</VBtn>
    </template>

    <!-- Dialog : programmer un créneau pour ce collaborateur -->
    <VDialog v-model="dialog" max-width="460">
      <VCard>
        <VCardItem><VCardTitle>{{ t('employeeFile.scheduleShift') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <AppDateTimePicker
            v-model="form.date"
            :label="t('employeeFile.date') + ' *'"
            :config="{ dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y' }"
            data-test="fiche-shift-date"
            class="mb-3"
          />
          <VRow>
            <VCol cols="6">
              <AppTextField v-model="form.start" type="time" :label="t('employeeFile.start') + ' *'" data-test="fiche-shift-start" />
            </VCol>
            <VCol cols="6">
              <AppTextField v-model="form.end" type="time" :label="t('employeeFile.end') + ' *'" data-test="fiche-shift-end" />
            </VCol>
          </VRow>
          <VCheckbox v-model="form.publish" :label="t('employeeFile.publishNow')" density="compact" class="mt-1" />
        </VCardText>
        <VDivider />
        <VCardText class="d-flex justify-end gap-2">
          <VBtn variant="outlined" color="secondary" @click="dialog = false">{{ t('common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="saving" :disabled="!form.date || !form.start || !form.end" data-test="fiche-shift-submit" @click="submit">
            {{ t('common.save') }}
          </VBtn>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>
