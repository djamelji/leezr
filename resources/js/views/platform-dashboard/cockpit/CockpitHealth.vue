<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Santé de la plateforme » for platform.system_health (view_system_health).
// The ONE dark surface of the cockpit. Source : SystemHealthService (email, AI, queues, scheduler, database, disk,
// alerts) — status per section only. No invented chart : the history of these measures is not stored (L2).
import AppLivePanel from '@/components/app/AppLivePanel.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t, te } = useI18n()
const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const d = computed(() => props.data || {})
const STATUS = { healthy: 'ok', ok: 'ok', warning: 'warning', critical: 'critical' }
const global = computed(() => (d.value.error ? 'unknown' : (STATUS[d.value.status] || 'unknown')))
const ICON = { email: 'tabler-mail', ai: 'tabler-sparkles', queues: 'tabler-stack-2', scheduler: 'tabler-clock-play', database: 'tabler-database', disk: 'tabler-device-sd-card', alerts: 'tabler-bell' }
const TONE = { healthy: 'success', warning: 'warning', critical: 'error' }
const ORDER = { critical: 0, warning: 1, healthy: 2 }
const sections = computed(() => [...(d.value.sections || [])].sort((a, b) => (ORDER[a.status] ?? 3) - (ORDER[b.status] ?? 3)))
const label = s => (te(`systemHealth.${s.key}`) ? t(`systemHealth.${s.key}`) : s.label)
const issues = computed(() => sections.value.filter(s => s.status !== 'healthy').length)
const cols = computed(() => (rootW.value >= 560 ? 3 : rootW.value >= 300 ? 2 : 1))
const checkedAt = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <AppLivePanel
      :eyebrow="t('cockpit.health.eyebrow', { time: checkedAt })"
      :title="t('cockpit.health.title')"
      :subtitle="global === 'ok' ? t('cockpit.health.allGood') : global === 'unknown' ? t('cockpit.unavailable') : t('cockpit.health.issues', { n: issues })"
      :status="global"
      :status-text="t(`cockpit.health.status.${global}`)"
    >
      <div
        v-if="loading && !data"
        class="ch-grid"
        :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }"
      >
        <div
          v-for="i in 6"
          :key="i"
          class="ch-cell ch-cell--skeleton"
        />
      </div>
      <div
        v-else
        class="ch-grid"
        :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }"
      >
        <RouterLink
          v-for="s in sections"
          :key="s.key"
          :to="{ name: 'platform-operations-tab', params: { tab: 'health' } }"
          class="ch-cell"
          :class="`ch-cell--${s.status}`"
        >
          <VIcon
            :icon="ICON[s.key] || 'tabler-point'"
            size="18"
            class="ch-cell__icon"
          />
          <span class="ch-cell__label text-truncate">{{ label(s) }}</span>
          <span
            class="ch-cell__state"
            :class="`text-${TONE[s.status] || 'secondary'}`"
          >{{ t(`cockpit.health.section.${s.status}`) }}</span>
        </RouterLink>
      </div>
      <template #footer>
        <VBtn
          v-if="rootH >= 300"
          block
          variant="tonal"
          color="white"
          prepend-icon="tabler-activity-heartbeat"
          :to="{ name: 'platform-operations-tab', params: { tab: 'health' } }"
        >
          {{ t('cockpit.health.open') }}
        </VBtn>
      </template>
    </AppLivePanel>
  </div>
</template>

<style scoped>
.ch-grid { display: grid; gap: .625rem; }
.ch-cell { display: grid; grid-template-columns: auto 1fr; grid-template-rows: auto auto; column-gap: .5rem; align-items: center; padding: .625rem .75rem; border-radius: 12px; background: rgba(255, 255, 255, .05); color: #fff; text-decoration: none; border: 1px solid transparent; transition: background .18s, border-color .18s; }
.ch-cell:hover { background: rgba(255, 255, 255, .09); }
.ch-cell--warning { border-color: rgba(var(--v-theme-warning), .45); }
.ch-cell--critical { border-color: rgba(var(--v-theme-error), .6); background: rgba(var(--v-theme-error), .12); }
.ch-cell__icon { grid-row: span 2; opacity: .8; }
.ch-cell__label { font-size: .8125rem; color: rgba(255, 255, 255, .72); }
.ch-cell__state { font-size: .9375rem; font-weight: 700; }
.ch-cell--skeleton { block-size: 58px; background: rgba(255, 255, 255, .06); }
</style>
