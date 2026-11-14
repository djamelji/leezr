<script setup>
// ADR-779 (Company Experience 2.0 / UX4) — en-tête UNIQUE de l'espace « Planning & temps ».
// Un titre, les vues de l'espace et l'action de la page. Les vues sont des ROUTES existantes
// (planning, temps & pointages, absences, mon temps) : aucune page fusionnée, aucune donnée déplacée.
// Chaque vue n'apparaît que si sa permission RÉELLE (celle de la route) ou la capacité PERSONNELLE
// renvoyée par le serveur (`/profile/space`) l'autorise : une personne ne voit jamais une vue vide
// de supervision, et aucune permission n'est élargie.
import { useDisplay } from 'vuetify'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'
import { usePersonalSpaceStore } from '@/modules/company/me/personal-space.store'

const props = defineProps({
  active: { type: String, required: true }, // planning | time | leave | me
  subtitle: { type: String, default: '' },
  bare: { type: Boolean, default: false }, // monté dans une autre page : actions seules
})

const { t } = useI18n()
const { smAndDown } = useDisplay()
const { can, moduleActive } = useCompanyPermissionContext()
const space = usePersonalSpaceStore()

onMounted(() => { space.load() })

const views = computed(() => [
  {
    key: 'planning',
    icon: 'tabler-calendar-week',
    to: { name: 'company-workforce-planning' },
    show: moduleActive('workforce_planning') && can('workforce.planning_view'),
  },
  {
    key: 'time',
    icon: 'tabler-clock',
    to: { name: 'company-workforce-time' },
    show: moduleActive('workforce') && can('workforce.timesheet_view'),
  },
  {
    key: 'leave',
    icon: 'tabler-beach',
    to: { name: 'company-workforce-leave' },
    show: moduleActive('workforce_leave') && can('workforce.leave_request')
      && (can('workforce.leave_view_all') || can('workforce.leave_approve')),
  },
  {
    key: 'me',
    icon: 'tabler-calendar-time',
    to: { name: 'company-workforce-me' },
    show: space.can('time_clock'),
  },
].filter(v => v.show || v.key === props.active))
</script>

<template>
  <div class="time-space-header mb-5">
    <div class="d-flex align-center flex-wrap gap-3 mb-3">
      <div
        v-if="!bare"
        class="min-w-0 flex-grow-1"
      >
        <h4 class="text-h4">
          {{ t('timeSpace.title') }}
        </h4>
        <p
          v-if="subtitle && !smAndDown"
          class="text-body-2 text-medium-emphasis mb-0"
        >
          {{ subtitle }}
        </p>
      </div>
      <VSpacer v-else />
      <div class="d-flex align-center flex-wrap gap-2">
        <slot name="actions" />
      </div>
    </div>
    <VTabs
      v-if="!bare && views.length > 1"
      :model-value="active"
      color="primary"
      :grow="smAndDown"
      density="comfortable"
      data-test="time-space-views"
    >
      <VTab
        v-for="v in views"
        :key="v.key"
        :value="v.key"
        :to="v.to"
        :data-test="`time-space-${v.key}`"
        :aria-label="t(`timeSpace.views.${v.key}`)"
      >
        <template v-if="!smAndDown">
          <VIcon
            :icon="v.icon"
            start
          />
          {{ t(`timeSpace.views.${v.key}`) }}
        </template>
        <!-- Smartphone : libellé court seul, réparti sur la largeur (aucune troncature). -->
        <span
          v-else
          class="text-body-2"
        >{{ t(`timeSpace.short.${v.key}`) }}</span>
      </VTab>
    </VTabs>
  </div>
</template>

<style scoped>
.time-space-header :deep(.v-tab) { min-inline-size: 0; padding-inline: 8px; }
.time-space-header :deep(.v-slide-group__prev),
.time-space-header :deep(.v-slide-group__next) { display: none; }
</style>
