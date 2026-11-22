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
import { useSettingsEntry } from '@/composables/useSettingsEntry'
import TimeExportDialog from '@/components/workforce/TimeExportDialog.vue'
import AppPageHeader from '@/components/app/AppPageHeader.vue'

const props = defineProps({
  active: { type: String, required: true }, // planning | time | leave | me
  subtitle: { type: String, default: '' },
  bare: { type: Boolean, default: false }, // monté dans une autre page : actions seules
})

const { t } = useI18n()
const { smAndDown } = useDisplay()
const { can, moduleActive } = useCompanyPermissionContext()
const space = usePersonalSpaceStore()
// ADR-781 (UX5) — les préférences de l'espace, à un clic, seulement si l'utilisateur peut les ouvrir.
const { to: preferencesTo } = useSettingsEntry('workforce-settings')

onMounted(() => { space.load() })

// ADR-844 (M7, FUN-06) — export of the space's data, from the space itself (no new menu entry), with the
// same right as the employees export ; the dialog starts on the data of the current view.
const exportOpen = ref(false)
const canExport = computed(() => props.active !== 'me' && can('workforce.export'))
const exportPreset = computed(() => ({ planning: ['planning'], time: ['daily', 'entries'], leave: ['leaves'] }[props.active] || ['daily']))

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

// ADR-861 (L7.8) — the four views no longer share one bare title : « Planning & temps » becomes the family line,
// each view gets its own title, sentence and browser-tab title (same pattern as Billing / Settings / Documents).
const pageTitle = computed(() => (props.active === 'me' ? t('timeSpace.meTitle') : t(`timeSpace.views.${props.active}`)))
watchEffect(() => { if (!props.bare) document.title = `${pageTitle.value} · ${t('timeSpace.title')} · Leezr` })
</script>

<template>
  <div class="time-space-header mb-5">
    <AppPageHeader
      v-if="!bare"
      :eyebrow="t('timeSpace.title')"
      :title="pageTitle"
      :description="smAndDown ? '' : subtitle"
      class="time-space-header__head"
      data-test="time-space-header"
    >
      <template #actions>
        <VBtn
          v-if="preferencesTo && !smAndDown"
          :to="preferencesTo"
          variant="text"
          color="secondary"
          prepend-icon="tabler-adjustments"
          data-test="space-preferences"
        >
          {{ t('timeSpace.preferences') }}
        </VBtn>
        <VBtn
          v-if="canExport"
          variant="tonal"
          color="primary"
          prepend-icon="tabler-file-export"
          data-test="time-export-open"
          @click="exportOpen = true"
        >
          {{ t('timeExport.button') }}
        </VBtn>
        <slot name="actions" />
      </template>
    </AppPageHeader>
    <div
      v-else
      class="d-flex align-center justify-end flex-wrap gap-2 mb-3"
    >
      <slot name="actions" />
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
    <TimeExportDialog
      v-if="canExport"
      v-model="exportOpen"
      :preset="exportPreset"
    />
  </div>
</template>

<style scoped>
.time-space-header__head { margin-block-end: 16px; }
.time-space-header :deep(.v-tab) { min-inline-size: 0; padding-inline: 8px; }
.time-space-header :deep(.v-slide-group__prev),
.time-space-header :deep(.v-slide-group__next) { display: none; }
</style>
