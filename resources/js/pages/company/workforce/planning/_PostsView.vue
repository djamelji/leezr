<script setup>
// M6.D (D3) — Vue « Postes / besoins de couverture ».
// Vue opérationnelle centrée sur les BESOINS (CoverageRequirement), pas sur les collaborateurs.
// AUTORITÉ SERVEUR UNIQUEMENT : tout vient de CoverageReadModel (/coverage/summary) et de
// /shifts/availability. Le frontend ne recalcule JAMAIS la couverture ni la compatibilité.
import { usePlanningStore } from '@/modules/company/workforce/planning.store'

const props = defineProps({
  fromIso: { type: String, required: true }, // début de semaine ISO 'YYYY-MM-DD'
  toIso: { type: String, required: true }, // fin de semaine ISO
  filters: { type: Object, default: () => ({}) }, // { departmentId, locationId, status }
  firstDay: { type: Number, default: 1 }, // 0=dim..6=sam
  canManage: { type: Boolean, default: false }, // workforce.planning_manage
})

const emit = defineEmits(['go-to-day', 'open-coverage', 'create'])

const { t, locale } = useI18n()
const store = usePlanningStore()

const loading = ref(false)
const requirements = ref([])

// ── Chargement (autorité serveur) ────────────────────────────
const load = async () => {
  loading.value = true
  try {
    const resp = await store.fetchCoverageSummary({ from: props.fromIso, to: props.toIso })
    requirements.value = resp?.requirements ?? []
  } catch {
    requirements.value = []
  } finally {
    loading.value = false
  }
}

watch(() => [props.fromIso, props.toIso], load, { immediate: true })
watch(() => props.filters, load, { deep: true })

// ── Filtrage d'affichage (département/lieu/statut) ───────────
// Les besoins portent department_id / work_location_id ; on filtre côté affichage sans recalcul.
const visibleReqs = computed(() => {
  const f = props.filters || {}

  return requirements.value.filter(r => {
    if (f.departmentId && r.department_id && r.department_id !== f.departmentId) return false
    if (f.locationId && r.work_location_id && r.work_location_id !== f.locationId) return false

    return true
  })
})

// ── Jours de la semaine (ordre réel from→to) ─────────────────
// TZ-safe : on formate en date CIVILE (pas de toISOString, qui décale en fuseau positif).
const isoOf = d => {
  const p = n => String(n).padStart(2, '0')

  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}
const days = computed(() => {
  const out = []
  const end = new Date(props.toIso + 'T00:00:00')
  const d = new Date(props.fromIso + 'T00:00:00')
  while (d <= end && out.length < 31) {
    out.push(isoOf(d))
    d.setDate(d.getDate() + 1)
  }

  return out
})

const reqsByDay = computed(() => {
  const map = {}
  days.value.forEach(iso => { map[iso] = [] })
  visibleReqs.value.forEach(r => {
    if (map[r.date]) map[r.date].push(r)
  })
  Object.values(map).forEach(list => list.sort((a, b) => (a.start_time || '').localeCompare(b.start_time || '')))

  return map
})

const dayLabel = iso => new Date(iso + 'T00:00:00').toLocaleDateString(locale.value, { weekday: 'long', day: 'numeric', month: 'long' })

// ── Synthèse semaine (agrégation d'affichage, pas un recalcul métier) ──
const weekTotals = computed(() => {
  let needed = 0; let assigned = 0; let gap = 0
  visibleReqs.value.forEach(r => {
    needed += r.headcount || 0
    assigned += r.assigned || 0
    if ((r.gap || 0) > 0) gap += r.gap
  })

  return { needed, assigned, gap }
})

const hasRequirements = computed(() => requirements.value.length > 0)

// ── Helpers d'affichage ──────────────────────────────────────
const statusColor = s => (s === 'under' ? 'error' : s === 'over' ? 'warning' : 'success')
const win = r => {
  if (!r.start_time && !r.end_time) return t('planning.posts.allDay', 'Journée entière')

  return `${(r.start_time || '').slice(0, 5)}–${(r.end_time || '').slice(0, 5)}`
}
const priorityChip = p => (p === 'critical' || p === 'high'
  ? { color: p === 'critical' ? 'error' : 'warning', label: t('planning.posts.priority.' + p, p === 'critical' ? 'Critique' : 'Haute') }
  : null)

// Largeur proportionnelle d'un intervalle dans la mini-barre (fallback : répartition égale).
const toMin = hhmm => {
  const [h, m] = (hhmm || '00:00').split(':').map(Number)

  return h * 60 + m
}
const intervalWidth = (intervals, iv) => {
  const total = intervals.reduce((acc, x) => acc + Math.max(1, toMin(x.end) - toMin(x.start)), 0) || 1

  return (Math.max(1, toMin(iv.end) - toMin(iv.start)) / total) * 100
}

// ── Dépliage + salariés compatibles (chargés à la demande) ───
const expanded = ref(null) // requirement_id déplié
const compatLoading = ref(false)
const compatible = ref([]) // [{employee_id, employee_name, job_role, availability, job_role_match}]

const rowKey = r => `${r.requirement_id}:${r.date}`

const toggleRow = async r => {
  const key = rowKey(r)
  if (expanded.value === key) { expanded.value = null; return }
  expanded.value = key
  compatible.value = []
  if ((r.gap || 0) <= 0) return

  compatLoading.value = true
  try {
    const start = `${r.date}T${r.start_time || '00:00:00'}`
    const end = `${r.date}T${r.end_time || '23:59:59'}`
    const list = await store.fetchAvailability({ start, end })
    compatible.value = (Array.isArray(list) ? list : (list?.data ?? [])).filter(e => e.availability === 'available')
  } catch {
    compatible.value = []
  } finally {
    compatLoading.value = false
  }
}
</script>

<template>
  <div data-test="posts-view">
    <VProgressLinear
      v-if="loading"
      indeterminate
      color="primary"
      class="mb-2"
    />

    <!-- État vide UTILE : aucune exigence configurée -->
    <VCard
      v-if="!loading && !hasRequirements"
      data-test="posts-empty"
      variant="tonal"
    >
      <VCardText class="text-center py-10">
        <VIcon
          icon="tabler-layout-dashboard"
          size="48"
          class="mb-3 text-disabled"
        />
        <h6 class="text-h6 mb-1">
          {{ t('planning.posts.empty.title', 'Aucune exigence de couverture configurée') }}
        </h6>
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ t('planning.posts.empty.subtitle', 'Définissez vos besoins par créneau et par poste pour suivre les affectations et les déficits.') }}
        </p>
        <VBtn
          v-if="canManage"
          color="primary"
          prepend-icon="tabler-settings"
          data-test="posts-configure"
          @click="emit('open-coverage')"
        >
          {{ t('planning.posts.empty.configure', 'Configurer la couverture') }}
        </VBtn>
      </VCardText>
    </VCard>

    <template v-else-if="!loading">
      <!-- Synthèse semaine -->
      <VCard class="mb-4">
        <VCardText class="d-flex flex-wrap gap-6 align-center">
          <div>
            <div class="text-caption text-medium-emphasis">
              {{ t('planning.posts.totals.needed', 'Besoin') }}
            </div>
            <div class="text-h5 font-weight-bold">
              {{ weekTotals.needed }}
            </div>
          </div>
          <div>
            <div class="text-caption text-medium-emphasis">
              {{ t('planning.posts.totals.assigned', 'Affecté') }}
            </div>
            <div class="text-h5 font-weight-bold">
              {{ weekTotals.assigned }}
            </div>
          </div>
          <div>
            <div class="text-caption text-medium-emphasis">
              {{ t('planning.posts.totals.gap', 'À pourvoir') }}
            </div>
            <div
              class="text-h5 font-weight-bold"
              :class="weekTotals.gap > 0 ? 'text-error' : 'text-success'"
            >
              {{ weekTotals.gap }}
            </div>
          </div>
          <VSpacer />
          <VBtn
            v-if="canManage"
            variant="tonal"
            size="small"
            prepend-icon="tabler-settings"
            data-test="posts-configure"
            @click="emit('open-coverage')"
          >
            {{ t('planning.posts.manage', 'Gérer la couverture') }}
          </VBtn>
        </VCardText>
      </VCard>

      <!-- Un bloc par jour -->
      <VCard
        v-for="iso in days"
        :key="iso"
        class="mb-3"
        :data-test="`posts-day-${iso}`"
      >
        <VCardItem class="py-2">
          <VCardTitle class="text-body-1 text-capitalize">
            {{ dayLabel(iso) }}
          </VCardTitle>
          <template #append>
            <VBtn
              variant="text"
              size="small"
              append-icon="tabler-arrow-right"
              data-test="posts-goto-day"
              @click="emit('go-to-day', iso)"
            >
              {{ t('planning.posts.viewDay', 'Voir la journée') }}
            </VBtn>
          </template>
        </VCardItem>
        <VDivider />
        <VCardText v-if="!reqsByDay[iso].length" class="text-caption text-medium-emphasis py-3">
          {{ t('planning.posts.noRequirement', 'Aucun besoin ce jour.') }}
        </VCardText>
        <VList v-else density="compact">
          <template
            v-for="r in reqsByDay[iso]"
            :key="rowKey(r)"
          >
            <VListItem
              data-test="posts-req-row"
              :data-under="r.gap > 0 ? '1' : null"
              :class="r.gap > 0 ? 'posts-req-under' : ''"
              @click="toggleRow(r)"
            >
              <template #prepend>
                <span
                  class="posts-dot me-3"
                  :style="{ background: r.category_color || '#a8aaae' }"
                />
              </template>
              <VListItemTitle class="d-flex align-center gap-2">
                <span class="font-weight-medium">{{ r.category_name || r.label || t('planning.coverage.anyPresence', 'Présence requise') }}</span>
                <VChip
                  v-if="priorityChip(r.priority)"
                  :color="priorityChip(r.priority).color"
                  size="x-small"
                  variant="tonal"
                >
                  {{ priorityChip(r.priority).label }}
                </VChip>
              </VListItemTitle>
              <VListItemSubtitle>{{ win(r) }}</VListItemSubtitle>

              <!-- Mini-barre d'intervalles : où est le déficit/sureffectif -->
              <div
                v-if="r.intervals && r.intervals.length"
                class="posts-bar mt-1"
              >
                <span
                  v-for="(iv, i) in r.intervals"
                  :key="i"
                  class="posts-bar__seg"
                  :class="`posts-bar__seg--${iv.status}`"
                  :style="{ width: intervalWidth(r.intervals, iv) + '%' }"
                  :title="`${iv.start}–${iv.end} · ${iv.assigned}`"
                />
              </div>

              <template #append>
                <div class="d-flex align-center gap-2">
                  <span
                    class="text-body-2 font-weight-medium"
                    :class="statusColor(r.status) === 'error' ? 'text-error' : (statusColor(r.status) === 'warning' ? 'text-warning' : 'text-success')"
                  >{{ r.assigned }}/{{ r.headcount }}</span>
                  <VChip
                    :color="statusColor(r.status)"
                    size="x-small"
                    variant="tonal"
                  >
                    {{ t('planning.posts.status.' + r.status, r.status === 'under' ? 'Déficit' : r.status === 'over' ? 'Sureffectif' : 'Couvert') }}
                  </VChip>
                  <VIcon :icon="expanded === rowKey(r) ? 'tabler-chevron-up' : 'tabler-chevron-down'" size="18" />
                </div>
              </template>
            </VListItem>

            <!-- Dépliage : salariés compatibles pour un créneau en déficit -->
            <VListItem
              v-if="expanded === rowKey(r)"
              class="posts-expand"
            >
              <div v-if="r.gap <= 0" class="text-caption text-success py-1">
                {{ t('planning.posts.covered', 'Ce créneau est couvert.') }}
              </div>
              <template v-else>
                <div class="text-caption text-medium-emphasis mb-2">
                  {{ t('planning.posts.compatibleTitle', 'Salariés disponibles pour ce créneau') }}
                </div>
                <VProgressLinear v-if="compatLoading" indeterminate color="primary" class="mb-2" />
                <div v-else-if="!compatible.length" class="text-caption text-disabled py-1">
                  {{ t('planning.posts.noCompatible', 'Aucun salarié disponible sur ce créneau.') }}
                </div>
                <div v-else class="d-flex flex-wrap gap-2">
                  <VChip
                    v-for="e in compatible"
                    :key="e.employee_id"
                    data-test="posts-compatible-emp"
                    size="small"
                    variant="tonal"
                    color="success"
                    prepend-icon="tabler-user-check"
                  >
                    {{ e.employee_name }}
                    <VIcon
                      v-if="e.job_role_match"
                      icon="tabler-star"
                      size="12"
                      class="ms-1 text-warning"
                      :title="t('planning.posts.roleMatch', 'Rôle correspondant')"
                    />
                  </VChip>
                </div>
                <div class="mt-3 d-flex gap-2">
                  <VBtn
                    v-if="canManage"
                    color="primary"
                    size="small"
                    prepend-icon="tabler-calendar-plus"
                    @click.stop="emit('create', { date: r.date, categoryId: r.coverage_category_id })"
                  >
                    {{ t('planning.posts.assign', 'Affecter') }}
                  </VBtn>
                  <VBtn
                    variant="text"
                    size="small"
                    append-icon="tabler-arrow-right"
                    @click.stop="emit('go-to-day', r.date)"
                  >
                    {{ t('planning.posts.viewDay', 'Voir la journée') }}
                  </VBtn>
                </div>
              </template>
            </VListItem>
          </template>
        </VList>
      </VCard>
    </template>
  </div>
</template>

<style scoped lang="scss">
.posts-dot {
  display: inline-block;
  inline-size: 10px;
  block-size: 10px;
  border-radius: 50%;
  flex: 0 0 auto;
}

.posts-req-under {
  border-inline-start: 3px solid rgb(var(--v-theme-error));
}

.posts-bar {
  display: flex;
  inline-size: 100%;
  max-inline-size: 320px;
  block-size: 6px;
  border-radius: 3px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.08);

  &__seg {
    block-size: 100%;

    &--under { background: rgb(var(--v-theme-error)); }
    &--covered { background: rgb(var(--v-theme-success)); }
    &--over { background: rgb(var(--v-theme-warning)); }
  }
}

.posts-expand {
  background: rgba(var(--v-theme-on-surface), 0.03);
}
</style>
