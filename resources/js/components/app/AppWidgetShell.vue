<script setup>
// Leezr 5.0 (L0, ADR-855) — common widget shell: ONE header rhythm (icon, title, subtitle, period, link), the
// loading / empty / error states with a stable height, and the presentation mode measured on the REAL container
// by the existing engine (useSurfacePresentation, ADR-748/750) passed to the body slot.
//   bare : inside a dashboard tile (DashboardGrid already draws the card) → no second card.
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import AppSegmented from '@/components/app/AppSegmented.vue'

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  icon: { type: String, default: '' },
  color: { type: String, default: 'primary' },
  periods: { type: Array, default: () => [] }, // [{ value, label }] or strings
  link: { type: String, default: '' },
  to: { type: [String, Object], default: null },
  state: { type: String, default: 'ready' }, // ready | loading | empty | error
  emptyIcon: { type: String, default: 'tabler-chart-dots' },
  emptyTitle: { type: String, default: '' },
  emptyText: { type: String, default: '' },
  errorText: { type: String, default: '' },
  bare: { type: Boolean, default: false },
  flat: { type: Boolean, default: false },
})

const emit = defineEmits(['retry'])
const period = defineModel('period', { type: String, default: null })

const root = ref(null)
const { mode, rootW, rootH } = useSurfacePresentation(root)
const tight = computed(() => rootW.value > 0 && rootW.value < 420)
const { t } = useI18n()


// ADR-856 — « Retry » : the parent may handle it (@retry) ; the dashboards also listen to this event to re-resolve
// their widgets through the existing engine (a widget never fetches its batch data itself).
const retry = () => {
  emit('retry')
  window.dispatchEvent(new CustomEvent('lzr-dashboard-retry'))
}
</script>

<template>
  <div
    ref="root"
    class="app-widget h-100"
    :class="[`app-widget--${mode}`, { 'app-widget--bare': bare }]"
  >
    <VCard
      class="app-widget__card h-100 d-flex flex-column"
      :class="bare ? 'app-widget__card--bare' : ['lzr-card', flat ? 'lzr-card--flat' : 'lzr-card--raised']"
      :flat="bare"
    >
      <div class="app-widget__head">
        <VAvatar
          v-if="icon"
          :color="color"
          variant="tonal"
          rounded="lg"
          :size="mode === 'micro' ? 30 : 34"
        >
          <VIcon
            :icon="icon"
            :size="mode === 'micro' ? 17 : 19"
          />
        </VAvatar>
        <div class="min-w-0 flex-grow-1">
          <div class="app-widget__title text-truncate">
            {{ title }}
          </div>
          <div
            v-if="subtitle && mode !== 'micro'"
            class="app-widget__subtitle text-truncate"
          >
            {{ subtitle }}
          </div>
        </div>
        <AppSegmented
          v-if="periods.length && !['micro', 'compact'].includes(mode) && !tight"
          v-model="period"
          :items="periods"
          size="small"
        />
        <slot name="head-actions" />
        <template v-if="link && to">
          <VBtn
            v-if="!tight"
            :to="to"
            variant="text"
            size="small"
            append-icon="tabler-arrow-right"
            class="lzr-text-action flex-shrink-0"
          >
            {{ link }}
          </VBtn>
          <VBtn
            v-else
            :to="to"
            :aria-label="link"
            icon="tabler-arrow-right"
            variant="text"
            size="small"
            class="lzr-text-action flex-shrink-0"
          />
        </template>
      </div>

      <div class="app-widget__body flex-grow-1">
        <div
          v-if="state === 'loading'"
          class="app-widget__loading"
        >
          <VSkeletonLoader
            type="heading"
            class="bg-transparent"
          />
          <VSkeletonLoader
            v-if="mode !== 'micro'"
            type="image"
            class="bg-transparent flex-grow-1 app-widget__skeleton-img"
          />
        </div>
        <AppEmptyState
          v-else-if="state === 'empty'"
          :icon="emptyIcon"
          :title="emptyTitle || t('appWidget.emptyTitle')"
          :text="mode === 'micro' ? '' : emptyText"
          compact
        />
        <AppEmptyState
          v-else-if="state === 'error'"
          icon="tabler-plug-connected-x"
          color="error"
          :title="t('appWidget.errorTitle')"
          :text="mode === 'micro' ? '' : (errorText || t('appWidget.errorText'))"
          :action="t('appWidget.retry')"
          compact
          @action="retry"
        />
        <slot
          v-else
          :mode="mode"
          :w="rootW"
          :h="rootH"
        />
      </div>
    </VCard>
  </div>
</template>

<style scoped>
.app-widget__card--bare { background: transparent !important; box-shadow: none !important; border: 0 !important; border-radius: inherit; }
.app-widget__head { display: flex; align-items: center; gap: .75rem; padding: 1rem 1.125rem .5rem; min-block-size: 56px; }
.app-widget--micro .app-widget__head { padding: .75rem .875rem .25rem; min-block-size: 44px; gap: .5rem; }
.app-widget__title { font-size: 1rem; font-weight: 600; letter-spacing: -.01em; color: rgb(var(--v-theme-on-surface)); }
.app-widget--micro .app-widget__title { font-size: .875rem; }
.app-widget__subtitle { font-size: .8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-widget__body { padding: .25rem 1.125rem 1rem; min-block-size: 0; display: flex; flex-direction: column; position: relative; }
.app-widget--micro .app-widget__body { padding: 0 .875rem .75rem; }
.app-widget__loading { flex: 1 1 auto; display: flex; flex-direction: column; gap: .75rem; padding-block-start: .25rem; }
.app-widget__skeleton-img :deep(.v-skeleton-loader__image) { block-size: 100%; min-block-size: 60px; border-radius: 12px; }
</style>
