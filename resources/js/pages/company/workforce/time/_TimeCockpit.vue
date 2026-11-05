<script setup>
// W-G5 (ADR-646) — cockpit Pointage : synthèse opérationnelle + tableau prévu/réalisé réconcilié +
// correction guidée (motif obligatoire). Données scopées/filtrées côté serveur. Aucun recalcul paie.
import { useTimesheetsStore } from '@/modules/company/workforce/timesheets.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import { useDisplay } from 'vuetify'
import EmployeePicker from '@/components/workforce/EmployeePicker.vue'
// PRODUCT-UX-2 (LOT B2) — panneau « Journée collaborateur » (constatation → action, dans le contexte).
import DayDetailPanel from './_DayDetailPanel.vue'

const emit = defineEmits(['navigate']) // { tab, employeeId } — demande à l'espace de basculer d'onglet
const { t, locale } = useI18n()
const { xs } = useDisplay()
const { toast } = useAppToast()
const { can } = useCan()
const router = useRouter()
const store = useTimesheetsStore()
const employeesStore = useEmployeesStore()

// ── Panneau Journée collaborateur ──
const dayPanelOpen = ref(false)
const dayPanelRow = ref(null)
const openDay = row => { dayPanelRow.value = row; dayPanelOpen.value = true }

const today = new Date().toISOString().slice(0, 10)
const date = ref(today)
const department = ref(null)
const employee = ref(null)
const statusFilter = ref(null)
const summary = ref(null)
const board = ref([])
const loading = ref(false)

const departmentOptions = computed(() => employeesStore.departments.map(d => ({ title: d.name, value: d.id })))
const statusOptions = [
  { title: t('timeCockpit.status.working'), value: 'working' },
  { title: t('timeCockpit.status.on_break'), value: 'on_break' },
  { title: t('timeCockpit.status.completed'), value: 'completed' },
  { title: t('timeCockpit.status.idle'), value: 'idle' },
  { title: t('timeCockpit.status.on_leave'), value: 'on_leave' },
]

// KPI cliquables → appliquent le filtre de statut correspondant.
// `mobile:true` = indicateur réellement utile en synthèse réduite (qui travaille + ce qui demande une action).
const kpis = computed(() => {
  const s = summary.value || {}

  return [
    { key: 'working', icon: 'tabler-player-play', color: 'success', value: s.working, status: 'working', mobile: true },
    { key: 'on_break', icon: 'tabler-coffee', color: 'warning', value: s.on_break, status: 'on_break' },
    { key: 'completed', icon: 'tabler-circle-check', color: 'info', value: s.completed, status: 'completed' },
    { key: 'not_clocked', icon: 'tabler-user-off', color: 'secondary', value: s.not_clocked, status: null, mobile: true },
    { key: 'incomplete', icon: 'tabler-alert-triangle', color: 'warning', value: s.incomplete, status: null },
    { key: 'anomalies', icon: 'tabler-exclamation-circle', color: 'error', value: s.anomalies, status: null, mobile: true },
    { key: 'pending_timesheets', icon: 'tabler-file-time', color: 'primary', value: s.pending_timesheets, status: null, mobile: true },
  ]
})
// Mobile : synthèse réduite aux indicateurs d'action (§3) ; desktop/tablette : tous.
const visibleKpis = computed(() => xs.value ? kpis.value.filter(k => k.mobile) : kpis.value)

// Garde de correctibilité HONNÊTE (§5) : le serveur n'autorise la correction que sur un pointage
// `completed` ou `idle` (un pointage en cours `working`/`on_break` n'est pas corrigeable via ce workflow).
const CORRECTABLE_STATUSES = ['completed', 'idle']
const isCorrectable = row => canCorrect.value && !!row.time_entry_id && CORRECTABLE_STATUSES.includes(row.status)

// L'écart (réalisé − prévu) n'a de sens qu'une fois le pointage TERMINÉ : tant que la personne est
// « en poste »/« en pause », on n'affiche pas d'écart (le réalisé est partiel → faux négatif trompeur).
const IN_PROGRESS = ['working', 'on_break']
const gapVisible = row => row.gap_minutes !== null && row.gap_minutes !== undefined && !IN_PROGRESS.includes(row.status)

const reconLabel = r => t('timeCockpit.recon.' + r, r)
const reconColor = r => ({ matched: 'success', ambiguous: 'warning', not_clocked: 'secondary', no_shift: 'info' }[r] || 'secondary')
const statusColor = s => ({ working: 'success', on_break: 'warning', completed: 'info', on_leave: 'primary', idle: 'secondary' }[s] || 'secondary')

const fmtTime = iso => iso ? new Date(iso).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) : null
const fmtMinutes = m => (m === null || m === undefined) ? null : `${Math.floor(m / 60)}h${String(m % 60).padStart(2, '0')}`
// Jamais "00:00" quand inconnu : « Non renseigné ».
const orNa = v => v ?? `— ${t('timeCockpit.notProvided')}`

const load = async () => {
  loading.value = true
  try {
    const [s, b] = await Promise.all([
      store.fetchCockpitSummary(date.value),
      store.fetchCockpitBoard({ date: date.value, departmentId: department.value || undefined, employeeId: employee.value || undefined, status: statusFilter.value || undefined }),
    ])
    summary.value = s
    board.value = b
  } catch { toast(t('common.error'), 'error') }
  finally { loading.value = false }
}

const applyKpi = kpi => { if (kpi.status !== undefined) { statusFilter.value = kpi.status; load() } }

watchDebounced([date, department, employee, statusFilter], load, { debounce: 300 })
onMounted(() => { employeesStore.fetchDepartments(); load() })

// ── Correction guidée ──
const correctOpen = ref(false)
const correctRow = ref(null)
const correctForm = ref({ clock_in: '', clock_out: '', reason: '' })
const correcting = ref(false)
const canCorrect = computed(() => can('workforce.time_manage'))

const openCorrect = row => {
  correctRow.value = row
  correctForm.value = {
    clock_in: row.actual_start ? row.actual_start.slice(0, 16) : '',
    clock_out: row.actual_end ? row.actual_end.slice(0, 16) : '',
    reason: '',
  }
  correctOpen.value = true
}
const submitCorrect = async () => {
  if (!correctForm.value.reason) return
  correcting.value = true
  try {
    await store.correctTimeEntry(correctRow.value.time_entry_id, {
      clockIn: correctForm.value.clock_in ? correctForm.value.clock_in + ':00' : undefined,
      clockOut: correctForm.value.clock_out ? correctForm.value.clock_out + ':00' : undefined,
      reason: correctForm.value.reason,
    })
    toast(t('timeCockpit.correctionSaved'), 'success')
    correctOpen.value = false
    load()
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  } finally { correcting.value = false }
}
</script>

<template>
  <div>
    <!-- Filtres -->
    <VCard class="mb-4">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="6" md="3">
            <AppDateTimePicker
              v-model="date"
              :label="t('timeCockpit.date')"
              :config="{ dateFormat: 'Y-m-d' }"
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6" md="3">
            <AppSelect
              v-model="department"
              :items="departmentOptions"
              :placeholder="t('employees.filters.department')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6" md="3">
            <EmployeePicker v-model="employee" :label="t('leaves.columns.employee')" clearable density="compact" />
          </VCol>
          <VCol cols="12" sm="6" md="3">
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="t('timeCockpit.status.label')"
              clearable
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- KPIs -->
    <VRow class="card-grid card-grid-xs mb-4">
      <VCol
        v-for="k in visibleKpis"
        :key="k.key"
        cols="6"
        sm="4"
        md="3"
      >
        <VCard
          :class="{ 'cursor-pointer': k.status !== null }"
          @click="k.status !== null && applyKpi(k)"
        >
          <VCardText class="d-flex align-center gap-3">
            <VAvatar :color="k.color" variant="tonal" rounded size="40">
              <VIcon :icon="k.icon" size="22" />
            </VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">
                {{ k.value ?? 0 }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('timeCockpit.kpi.' + k.key) }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tableau opérationnel -->
    <VCard>
      <VCardText v-if="loading" class="text-center py-8">
        <VProgressCircular indeterminate color="primary" />
      </VCardText>
      <template v-else>
        <!-- Desktop / tablette : table réconciliée prévu/réel/écart (pas de compression mobile). -->
        <VTable v-if="!xs" class="text-no-wrap" density="comfortable">
          <thead>
            <tr>
              <th>{{ t('leaves.columns.employee') }}</th>
              <th>{{ t('timeCockpit.col.status') }}</th>
              <th>{{ t('timeCockpit.col.planned') }}</th>
              <th>{{ t('timeCockpit.col.actual') }}</th>
              <th>{{ t('timeCockpit.col.worked') }}</th>
              <th>{{ t('timeCockpit.col.gap') }}</th>
              <th>{{ t('timeCockpit.col.recon') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in board" :key="r.employee_id" class="cursor-pointer" @click="openDay(r)">
              <td class="font-weight-medium">
                {{ r.employee_name }}
              </td>
              <td>
                <VChip :color="statusColor(r.status)" size="x-small" variant="tonal">
                  {{ t('timeCockpit.status.' + r.status, r.status) }}
                </VChip>
              </td>
              <td>{{ fmtTime(r.planned_start) ? `${fmtTime(r.planned_start)} – ${fmtTime(r.planned_end)}` : orNa(null) }}</td>
              <td>
                <span v-if="r.actual_start">{{ fmtTime(r.actual_start) }} – {{ fmtTime(r.actual_end) || t('timeCockpit.notClockedOut') }}</span>
                <span v-else class="text-disabled">— {{ t('timeCockpit.notClocked') }}</span>
              </td>
              <td>{{ orNa(fmtMinutes(r.worked_minutes)) }}</td>
              <td>
                <span v-if="gapVisible(r)" :class="r.gap_minutes < 0 ? 'text-error' : 'text-success'">
                  {{ r.gap_minutes > 0 ? '+' : '' }}{{ fmtMinutes(Math.abs(r.gap_minutes)) }}
                </span>
                <span v-else class="text-disabled">—</span>
              </td>
              <td>
                <div class="d-flex align-center gap-1">
                  <VChip :color="reconColor(r.reconciliation)" size="x-small" variant="tonal">
                    {{ reconLabel(r.reconciliation) }}
                  </VChip>
                  <VIcon
                    v-if="r.incomplete"
                    icon="tabler-alert-triangle"
                    color="warning"
                    size="16"
                  >
                    <VTooltip activator="parent" location="top">{{ t('dayPanel.findings.incomplete') }}</VTooltip>
                  </VIcon>
                </div>
              </td>
              <td>
                <VBtn
                  v-if="isCorrectable(r)"
                  size="x-small"
                  variant="tonal"
                  prepend-icon="tabler-edit"
                  data-test="cockpit-correct"
                  @click.stop="openCorrect(r)"
                >
                  {{ t('timeCockpit.correct') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>

        <!-- Mobile : liste tactile compacte — nom + statut, prévu vs réel, écart, indicateur, détail au toucher. -->
        <VList v-else class="py-0">
          <template v-for="(r, idx) in board" :key="r.employee_id">
            <VListItem class="cockpit-row px-3" :data-test="`cockpit-row-${r.employee_id}`" @click="openDay(r)">
              <div class="d-flex align-center justify-space-between gap-2">
                <span class="font-weight-medium text-truncate">{{ r.employee_name }}</span>
                <VChip :color="statusColor(r.status)" size="x-small" variant="tonal">
                  {{ t('timeCockpit.status.' + r.status, r.status) }}
                </VChip>
              </div>
              <div class="d-flex align-center gap-3 text-caption text-medium-emphasis mt-1">
                <span>
                  <VIcon icon="tabler-calendar" size="13" class="me-1" />
                  {{ fmtTime(r.planned_start) ? `${fmtTime(r.planned_start)}–${fmtTime(r.planned_end)}` : '—' }}
                </span>
                <span :class="r.actual_start ? '' : 'text-disabled'">
                  <VIcon icon="tabler-clock-record" size="13" class="me-1" />
                  <template v-if="r.actual_start">{{ fmtTime(r.actual_start) }}–{{ fmtTime(r.actual_end) || '…' }}</template>
                  <template v-else>{{ t('timeCockpit.notClocked') }}</template>
                </span>
              </div>
              <template #append>
                <div class="d-flex align-center gap-2">
                  <span
                    v-if="gapVisible(r)"
                    class="text-caption font-weight-medium"
                    :class="r.gap_minutes < 0 ? 'text-error' : 'text-success'"
                  >
                    {{ r.gap_minutes > 0 ? '+' : '' }}{{ fmtMinutes(Math.abs(r.gap_minutes)) }}
                  </span>
                  <VIcon v-if="r.incomplete" icon="tabler-alert-triangle" color="warning" size="18" />
                  <VIcon icon="tabler-chevron-right" size="18" class="text-disabled" />
                </div>
              </template>
            </VListItem>
            <VDivider v-if="idx < board.length - 1" />
          </template>
        </VList>

        <div v-if="!board.length" class="text-center text-disabled py-8">
          {{ t('timeCockpit.empty') }}
        </div>
      </template>
    </VCard>

    <!-- Drawer de correction guidée -->
    <VNavigationDrawer class="wf-scroll-drawer" v-model="correctOpen" temporary location="end" width="420">
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ t('timeCockpit.correctTitle') }}
        </h5>
        <VBtn icon variant="text" size="small" @click="correctOpen = false">
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>
      <VDivider />
      <div v-if="correctRow" class="pa-4">
        <p class="font-weight-medium mb-1">
          {{ correctRow.employee_name }}
        </p>
        <!-- Contexte prévu (planning) -->
        <VAlert
          v-if="correctRow.planned_start"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('timeCockpit.col.planned') }}: {{ fmtTime(correctRow.planned_start) }} – {{ fmtTime(correctRow.planned_end) }}
        </VAlert>

        <VForm @submit.prevent="submitCorrect">
          <AppTextField
            v-model="correctForm.clock_in"
            :label="t('timeCockpit.clockIn')"
            type="datetime-local"
            class="mb-3"
          />
          <AppTextField
            v-model="correctForm.clock_out"
            :label="t('timeCockpit.clockOut')"
            type="datetime-local"
            class="mb-3"
          />
          <VTextarea
            v-model="correctForm.reason"
            :label="t('timeCockpit.reason')"
            :rules="[v => !!v || t('validation.required')]"
            rows="2"
            auto-grow
            class="mb-3"
          />
          <div class="d-flex gap-3 justify-end">
            <VBtn variant="outlined" @click="correctOpen = false">
              {{ t('common.cancel') }}
            </VBtn>
            <VBtn
              type="submit"
              color="primary"
              :loading="correcting"
              :disabled="!correctForm.reason"
            >
              {{ t('common.save') }}
            </VBtn>
          </div>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- PRODUCT-UX-2 (LOT B2) : panneau Journée collaborateur (constatation → action) -->
    <DayDetailPanel
      v-model="dayPanelOpen"
      :row="dayPanelRow"
      :date="date"
      :can-manage="canCorrect"
      :can-planning-manage="can('workforce.planning_manage')"
      @corrected="load"
      @open-fiche="r => router.push(`/company/workforce/employees/${r.employee_id}`)"
      @go-planning="r => { dayPanelOpen = false; emit('navigate', { tab: 'planning', employeeId: r.employee_id }) }"
      @go-presence="r => { dayPanelOpen = false; emit('navigate', { tab: 'entries', employeeId: r.employee_id }) }"
    />
  </div>
</template>

<style lang="scss">
.cockpit-row {
  cursor: pointer;
  min-block-size: 56px; // cible tactile confortable (liste mobile)
}
</style>
