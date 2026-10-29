<script setup>
// W-G4A (ADR-644) — tableau Planning visuel (FullCalendar, déjà présent). Vues semaine/jour/liste,
// shifts (horodatés, brouillon vs publié) + absences W-G3 (journée), chevauchements signalés
// (bord rouge). Données scopées + filtrées + bornées CÔTÉ SERVEUR (/shifts/board). Lecture seule.
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import listPlugin from '@fullcalendar/list'
import interactionPlugin from '@fullcalendar/interaction'
import { usePlanningStore } from '@/modules/company/workforce/planning.store'
import { useWorldStore } from '@/core/stores/world'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  firstDay: { type: Number, default: null }, // premier jour de semaine (préférence Company)
  editable: { type: Boolean, default: false }, // drag-drop autorisé (brouillons uniquement)
})
const emit = defineEmits(['select', 'conflicts', 'move'])

const { locale } = useI18n()
const store = usePlanningStore()
const world = useWorldStore()
const refCalendar = ref()
const loading = ref(false)

const SHIFT_COLOR = { draft: '#a8aaae', published: '#7367f0', completed: '#28c76f' }
const CONFLICT = '#ea5455'
const ABSENCE_COLOR = { pending: '#ff9f43', approved: '#82868b', consumed: '#82868b' }

watch(() => props.filters, () => refCalendar.value?.getApi()?.refetchEvents(), { deep: true })

const fetchEvents = (info, successCallback) => {
  loading.value = true
  const from = info.startStr.slice(0, 10)
  const to = info.endStr.slice(0, 10)

  store.fetchBoard({ from, to, ...props.filters }).then(board => {
    emit('conflicts', board.conflicts || [])

    const shiftEvents = (board.shifts || []).map(s => {
      const isDraft = s.status === 'draft'

      return {
        id: s.id,
        title: s.employee_name + (s.location ? ` · ${s.location}` : ''),
        start: s.start,
        end: s.end,
        allDay: false,
        // Édition directe (drag/resize) UNIQUEMENT pour les brouillons — les publiés sont figés.
        editable: props.editable && isDraft,
        startEditable: props.editable && isDraft,
        durationEditable: props.editable && isDraft,
        backgroundColor: SHIFT_COLOR[s.status] || SHIFT_COLOR.published,
        borderColor: s.has_conflict ? CONFLICT : (SHIFT_COLOR[s.status] || SHIFT_COLOR.published),
        classNames: isDraft ? ['shift-draft'] : [],
        extendedProps: { ...s, kind: 'shift' },
      }
    })

    const absenceEvents = (board.absences || []).map(a => ({
      id: a.id,
      title: `🌴 ${a.employee_name} · ${a.leave_type}`,
      start: a.start,
      end: a.end,
      allDay: true,
      backgroundColor: ABSENCE_COLOR[a.status] || ABSENCE_COLOR.approved,
      borderColor: ABSENCE_COLOR[a.status] || ABSENCE_COLOR.approved,
      extendedProps: { ...a, kind: 'absence' },
    }))

    successCallback([...absenceEvents, ...shiftEvents])
  }).catch(() => successCallback([])).finally(() => { loading.value = false })
}

// Drag/resize d'un brouillon → on délègue au parent (confirmation + PUT verrou optimiste + revert).
const onEventChange = info => {
  if (info.event.extendedProps.kind !== 'shift' || info.event.extendedProps.status !== 'draft') {
    info.revert()

    return
  }
  emit('move', {
    shift: info.event.extendedProps,
    start: info.event.start?.toISOString(),
    end: info.event.end?.toISOString(),
    revert: info.revert,
  })
}

const calendarOptions = computed(() => ({
  plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
  initialView: 'timeGridWeek',
  locale: locale.value?.startsWith('fr') ? 'fr' : 'en',
  timeZone: world.timezone || 'local',
  // Premier jour de semaine : préférence Company (W-G4B) si fournie, sinon déduit de la locale par
  // FullCalendar (fr→lundi, en-US→dimanche). Jamais présumé lundi en dur.
  ...(props.firstDay !== null ? { firstDay: props.firstDay } : {}),
  height: 'auto',
  nowIndicator: true,
  editable: props.editable,
  eventDurationEditable: props.editable,
  headerToolbar: { start: 'prev,next today', center: 'title', end: 'timeGridWeek,timeGridDay,listWeek' },
  buttonText: { today: "Aujourd'hui", week: 'Semaine', day: 'Jour', list: 'Liste' },
  events: fetchEvents,
  eventClick({ event }) { emit('select', event.extendedProps) },
  eventDrop: onEventChange,
  eventResize: onEventChange,
  slotMinTime: '05:00:00',
  slotMaxTime: '24:00:00',
  expandRows: true,
}))

const refetch = () => refCalendar.value?.getApi()?.refetchEvents()
defineExpose({ refetch })
</script>

<template>
  <div class="planning-calendar">
    <VProgressLinear
      v-if="loading"
      indeterminate
      color="primary"
    />
    <FullCalendar
      ref="refCalendar"
      :options="calendarOptions"
    />
  </div>
</template>

<style lang="scss">
.planning-calendar {
  .fc {
    .fc-toolbar-title { font-size: 1.05rem; }
    .fc-event { cursor: pointer; font-size: 0.72rem; }
    // Brouillon : rayé pour le distinguer du publié.
    .shift-draft { background-image: repeating-linear-gradient(45deg, transparent, transparent 4px, rgba(255,255,255,.25) 4px, rgba(255,255,255,.25) 8px); opacity: .85; }
  }
}
</style>
