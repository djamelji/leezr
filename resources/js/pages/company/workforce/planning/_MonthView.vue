<script setup>
// M6.D — Vue MOIS (supervision). Synthèses RÉELLES par jour (planifié / brouillons / publiés /
// absences / alertes / couverture) à partir du board réel. PAS le détail de chaque tâche.
// La navigation (mois/année) est pilotée par le parent via `anchorIso` ; ce composant rend le mois
// correspondant et agrège les vraies données. Clic sur une journée → `go-to-day`.
import { usePlanningStore } from '@/modules/company/workforce/planning.store'

const props = defineProps({
  anchorIso: { type: String, required: true }, // date 'YYYY-MM-DD' DANS le mois affiché
  filters: { type: Object, default: () => ({}) }, // { departmentId, locationId, status }
  firstDay: { type: Number, default: 1 }, // 0=dim..6=sam
})
const emit = defineEmits(['go-to-day'])

const { t, locale } = useI18n()
const store = usePlanningStore()

// ── Dates civiles (jamais toISOString sur une Date locale → décalage TZ) ──
const pad = n => String(n).padStart(2, '0')
const toIso = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

const anchorParts = computed(() => props.anchorIso.split('-').map(Number)) // [y, m(1-12), d]

// Premier jour de la grille : 1er du mois reculé jusqu'au `firstDay` de la semaine.
const gridStart = computed(() => {
  const [y, m] = anchorParts.value
  const first = new Date(y, m - 1, 1)
  const offset = (first.getDay() - props.firstDay + 7) % 7

  return new Date(y, m - 1, 1 - offset)
})

const todayIso = computed(() => toIso(new Date()))

// Grille 6×7 = 42 cellules.
const cells = computed(() => {
  const gs = gridStart.value
  const [y, m] = anchorParts.value
  const arr = []
  for (let i = 0; i < 42; i++) {
    const d = new Date(gs.getFullYear(), gs.getMonth(), gs.getDate() + i)
    const iso = toIso(d)
    arr.push({
      iso,
      day: d.getDate(),
      inMonth: d.getMonth() === (m - 1) && d.getFullYear() === y,
      isToday: iso === todayIso.value,
    })
  }

  return arr
})

const weekdayLabels = computed(() => {
  const gs = gridStart.value
  const out = []
  for (let i = 0; i < 7; i++) {
    const d = new Date(gs.getFullYear(), gs.getMonth(), gs.getDate() + i)
    out.push(d.toLocaleDateString(locale.value, { weekday: 'short' }))
  }

  return out
})

const gridRange = computed(() => ({ from: cells.value[0].iso, to: cells.value[41].iso }))

// ── Données réelles ──────────────────────────────────────────
const loading = ref(false)
const board = ref({ shifts: [], absences: [], conflicts: [] })
const daily = ref({})

const load = async () => {
  loading.value = true
  try {
    const { from, to } = gridRange.value
    const [b, cov] = await Promise.all([
      store.fetchBoard({ from, to, ...props.filters }),
      store.fetchCoverageSummary({ from, to }).catch(() => ({ daily: {} })),
    ])
    board.value = b || { shifts: [], absences: [], conflicts: [] }
    daily.value = cov?.daily || {}
  } catch {
    board.value = { shifts: [], absences: [], conflicts: [] }
    daily.value = {}
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.anchorIso, load)
watch(() => props.filters, load, { deep: true })

// ── Agrégation par jour (données réelles, aucune valeur inventée) ──
const aggByDay = computed(() => {
  const map = {}
  cells.value.forEach(c => { map[c.iso] = { total: 0, drafts: 0, published: 0, absences: 0, alerts: 0, gap: 0 } })

  ;(board.value.shifts || []).forEach(s => {
    const iso = (s.date || '').slice(0, 10)
    if (!map[iso]) return
    map[iso].total++
    if (s.status === 'draft') map[iso].drafts++
    else if (s.status === 'published') map[iso].published++
    if (s.has_conflict) map[iso].alerts++
  })

  // Absence : couvre les jours [start, end) (end exclusif, convention FullCalendar).
  ;(board.value.absences || []).forEach(a => {
    cells.value.forEach(c => { if (c.iso >= a.start && c.iso < a.end) map[c.iso].absences++ })
  })

  // Conflits portant une date explicite (sinon on s'appuie sur has_conflict déjà compté).
  ;(board.value.conflicts || []).forEach(cf => {
    const iso = (cf.date || cf.start || '').slice(0, 10)
    if (map[iso]) map[iso].alerts++
  })

  Object.entries(daily.value || {}).forEach(([iso, d]) => { if (map[iso]) map[iso].gap = d.gap || 0 })

  return map
})

const agg = iso => aggByDay.value[iso] || { total: 0, drafts: 0, published: 0, absences: 0, alerts: 0, gap: 0 }
const isEmptyDay = iso => { const a = agg(iso); return a.total === 0 && a.absences === 0 }
</script>

<template>
  <VCard data-test="month-view">
    <VProgressLinear
      v-if="loading"
      indeterminate
      color="primary"
    />
    <VCardText>
      <!-- En-têtes jours -->
      <div class="month-grid month-grid--head">
        <div
          v-for="(w, i) in weekdayLabels"
          :key="`wd-${i}`"
          class="month-weekday"
          data-test="month-weekday-head"
        >
          {{ w }}
        </div>
      </div>

      <!-- Grille 6×7 -->
      <div
        class="month-grid"
        data-test="month-grid"
      >
        <button
          v-for="c in cells"
          :key="c.iso"
          type="button"
          class="month-day"
          :class="{
            'month-day--outside': !c.inMonth,
            'month-day--today': c.isToday,
            'month-day--empty': isEmptyDay(c.iso),
          }"
          :data-test="`month-day-${c.iso}`"
          :data-outside="!c.inMonth ? '1' : null"
          @click="emit('go-to-day', c.iso)"
        >
          <div class="month-day__head">
            <span class="month-day__num">{{ c.day }}</span>
            <span
              v-if="agg(c.iso).alerts"
              class="month-dot month-dot--alert"
              data-test="month-alert"
              :title="t('planning.month.alerts', 'Alertes')"
            />
            <span
              v-if="agg(c.iso).gap > 0"
              class="month-dot month-dot--gap"
              data-test="month-coverage-gap"
              :title="t('planning.month.coverageGap', 'Déficit de couverture')"
            />
          </div>

          <div
            v-if="!isEmptyDay(c.iso)"
            class="month-day__body"
          >
            <span
              v-if="agg(c.iso).published"
              class="month-pill month-pill--published"
              data-test="month-count-planned"
              :title="t('planning.month.published', 'Publiés')"
            >{{ agg(c.iso).published }}</span>
            <span
              v-if="agg(c.iso).drafts"
              class="month-pill month-pill--draft"
              data-test="month-count-drafts"
              :title="t('planning.month.drafts', 'Brouillons')"
            >{{ agg(c.iso).drafts }}</span>
            <span
              v-if="agg(c.iso).absences"
              class="month-pill month-pill--absence"
              data-test="month-count-absences"
              :title="t('planning.month.absences', 'Absences')"
            >🌴 {{ agg(c.iso).absences }}</span>
          </div>
        </button>
      </div>

      <!-- Légende -->
      <div class="d-flex gap-4 flex-wrap mt-4 text-caption align-center text-medium-emphasis">
        <span class="d-flex align-center gap-1"><span class="month-pill month-pill--published">n</span>{{ t('planning.month.published', 'Publiés') }}</span>
        <span class="d-flex align-center gap-1"><span class="month-pill month-pill--draft">n</span>{{ t('planning.month.drafts', 'Brouillons') }}</span>
        <span class="d-flex align-center gap-1">🌴 {{ t('planning.month.absences', 'Absences') }}</span>
        <span class="d-flex align-center gap-1"><span class="month-dot month-dot--alert" />{{ t('planning.month.alerts', 'Alertes') }}</span>
        <span class="d-flex align-center gap-1"><span class="month-dot month-dot--gap" />{{ t('planning.month.coverageGap', 'Déficit de couverture') }}</span>
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped lang="scss">
.month-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;

  &--head {
    margin-block-end: 6px;
    gap: 6px 6px;
  }
}

.month-weekday {
  padding-block: 4px;
  font-size: 0.72rem;
  font-weight: 600;
  text-align: center;
  text-transform: capitalize;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.month-day {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-block-size: 92px;
  padding: 6px 8px;
  text-align: start;
  cursor: pointer;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface));
  transition: border-color .15s ease, background .15s ease;

  &:hover { border-color: rgba(var(--v-theme-primary), 0.5); }
  &:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 1px; }

  &--outside { opacity: 0.45; }
  &--empty { background: rgba(var(--v-theme-on-surface), 0.015); }
  &--today {
    border-color: rgb(var(--v-theme-primary));
    box-shadow: inset 0 0 0 1px rgb(var(--v-theme-primary));
  }
}

.month-day__head {
  display: flex;
  align-items: center;
  gap: 4px;
}

.month-day__num {
  font-size: 0.8rem;
  font-weight: 600;
  line-height: 1;
  color: rgb(var(--v-theme-on-surface));

  .month-day--today & { color: rgb(var(--v-theme-primary)); }
}

.month-day__body {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  margin-block-start: auto;
}

.month-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-inline-size: 18px;
  padding: 1px 6px;
  font-size: 0.68rem;
  font-weight: 600;
  line-height: 1.4;
  border-radius: 999px;
  color: #fff;

  &--published { background: #7367f0; }
  &--draft { background: #a8aaae; }
  &--absence { background: rgba(130, 134, 139, 0.18); color: rgb(var(--v-theme-on-surface)); }
}

.month-dot {
  inline-size: 8px;
  block-size: 8px;
  margin-inline-start: auto;
  border-radius: 999px;

  &--alert { background: rgb(var(--v-theme-error)); margin-inline-start: 0; }
  &--gap { background: rgb(var(--v-theme-warning)); margin-inline-start: 0; }
}
</style>
