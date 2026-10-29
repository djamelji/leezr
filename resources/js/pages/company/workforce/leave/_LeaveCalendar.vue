<script setup>
// W-G3 (ADR-643) — calendrier visuel des congés (FullCalendar, déjà présent). Vues mois/semaine/
// liste, navigation, aujourd'hui, couleurs par statut. Les données sont scopées + filtrées côté
// SERVEUR (jamais de filtrage Vue pour la sécurité). Clic événement → détail (approbation inline).
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import listPlugin from '@fullcalendar/list'
import interactionPlugin from '@fullcalendar/interaction'
import { useLeavesStore } from '@/modules/company/workforce/leaves.store'

const props = defineProps({
  filters: { type: Object, default: () => ({}) }, // departmentId/leaveTypeId/employeeId/statuses
  firstDay: { type: Number, default: null }, // W-G4B : préférence Company de premier jour de semaine
})
const emit = defineEmits(['select', 'range'])

const { locale } = useI18n()
const store = useLeavesStore()
const refCalendar = ref()

// Couleurs par statut (alignées sur le thème Vuexy).
const STATUS_COLOR = {
  pending: '#ff9f43', approved: '#28c76f', consumed: '#00cfe8', rejected: '#ea5455', cancelled: '#a8aaae',
}

const currentRange = ref({ from: null, to: null })

// Les filtres changent → on refetch la plage courante (fetchEvents = source unique, scope serveur).
watch(() => props.filters, () => {
  refCalendar.value?.getApi()?.refetchEvents()
}, { deep: true })

const fetchEvents = (info, successCallback) => {
  const from = info.startStr.slice(0, 10)
  const to = info.endStr.slice(0, 10)

  store.fetchCalendar({ from, to, ...props.filters }).then(events => {
    currentRange.value = { from, to }
    emit('range', { from, to })
    successCallback((events || []).map(e => {
      const end = new Date(e.date_to)

      end.setDate(end.getDate() + 1) // FullCalendar : fin exclusive

      return {
        id: String(e.id),
        title: `${e.employee_name} · ${e.leave_type}`,
        start: e.date_from,
        end: end.toISOString().slice(0, 10),
        allDay: true,
        backgroundColor: STATUS_COLOR[e.status] || STATUS_COLOR.cancelled,
        borderColor: STATUS_COLOR[e.status] || STATUS_COLOR.cancelled,
        extendedProps: e,
      }
    }))
  }).catch(() => successCallback([]))
}

const calendarOptions = computed(() => ({
  plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
  initialView: 'dayGridMonth',
  locale: locale.value?.startsWith('fr') ? 'fr' : 'en',
  ...(props.firstDay !== null ? { firstDay: props.firstDay } : {}),
  height: 'auto',
  headerToolbar: {
    start: 'prev,next today',
    center: 'title',
    end: 'dayGridMonth,timeGridWeek,listMonth',
  },
  buttonText: { today: "Aujourd'hui", month: 'Mois', week: 'Semaine', list: 'Liste' },
  events: fetchEvents,
  eventClick({ event }) {
    emit('select', event.extendedProps)
  },
  displayEventTime: false,
  dayMaxEvents: 3,
}))

const refetch = () => refCalendar.value?.getApi()?.refetchEvents()
defineExpose({ refetch })
</script>

<template>
  <div class="leave-calendar">
    <VProgressLinear
      v-if="store.loading"
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
.leave-calendar {
  .fc {
    .fc-toolbar-title { font-size: 1.1rem; }
    .fc-event { cursor: pointer; font-size: 0.75rem; }
  }
}
</style>
