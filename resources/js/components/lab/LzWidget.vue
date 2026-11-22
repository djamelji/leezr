<script setup>
// Leezr 5.0 DESIGN LAB — widget shell (prototype). One header rhythm for every widget: icon, title, subtitle,
// optional segmented period control and a « see all » link. Body slot receives the presentation mode measured
// on the REAL container by the existing engine (useSurfacePresentation), so content can switch layout.
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  icon: { type: String, default: '' },
  color: { type: String, default: 'primary' },
  periods: { type: Array, default: () => [] },
  link: { type: String, default: '' },
  linkTo: { type: [String, Object], default: null },
  flat: { type: Boolean, default: false },
})

const period = defineModel('period', { type: String, default: null })
if (!period.value && props.periods.length) period.value = props.periods[0]

const root = ref(null)
const { mode, rootW, rootH } = useSurfacePresentation(root)
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <VCard
      class="lz-widget lzr-card h-100 d-flex flex-column"
      :class="flat ? 'lzr-card--flat' : 'lzr-card--raised'"
    >
      <div class="lz-widget__head">
        <VAvatar
          v-if="icon"
          :color="color"
          variant="tonal"
          rounded="lg"
          size="34"
        >
          <VIcon
            :icon="icon"
            size="19"
          />
        </VAvatar>
        <div class="min-w-0 flex-grow-1">
          <div class="lz-widget__title text-truncate">
            {{ title }}
          </div>
          <div
            v-if="subtitle && mode !== 'micro'"
            class="lz-widget__subtitle text-truncate"
          >
            {{ subtitle }}
          </div>
        </div>
        <div
          v-if="periods.length && !['micro', 'compact'].includes(mode)"
          class="lz-seg"
        >
          <button
            v-for="p in periods"
            :key="p"
            type="button"
            class="lz-seg__btn"
            :class="{ 'lz-seg__btn--on': period === p }"
            @click="period = p"
          >
            {{ p }}
          </button>
        </div>
        <slot name="head-actions" />
        <VBtn
          v-if="link && rootW >= 440"
          :to="linkTo || undefined"
          variant="text"
          size="small"
          append-icon="tabler-arrow-right"
          class="lzr-text-action flex-shrink-0"
        >
          {{ link }}
        </VBtn>
        <VBtn
          v-else-if="link"
          :to="linkTo || undefined"
          :aria-label="link"
          icon="tabler-arrow-right"
          variant="text"
          size="small"
          class="lzr-text-action flex-shrink-0"
        />
      </div>
      <div class="lz-widget__body flex-grow-1">
        <slot
          :mode="mode"
          :w="rootW"
          :h="rootH"
        />
      </div>
    </VCard>
  </div>
</template>

<style scoped>
.lz-widget__head { display: flex; align-items: center; gap: .75rem; padding: 1rem 1.125rem .5rem; min-block-size: 56px; }
.lz-widget__title { font-size: 1rem; font-weight: 600; letter-spacing: -.01em; }
.lz-widget__subtitle { font-size: .8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.lz-widget__body { padding: .25rem 1.125rem 1.125rem; min-block-size: 0; display: flex; flex-direction: column; }
.lz-seg { display: inline-flex; padding: 3px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), .06); }
.lz-seg__btn { font-size: .75rem; padding: .25rem .625rem; border-radius: 8px; color: rgba(var(--v-theme-on-surface), .7); transition: background .18s, color .18s, box-shadow .18s; white-space: nowrap; }
.lz-seg__btn--on { background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-on-surface)); font-weight: 600; box-shadow: 0 1px 3px rgba(0, 0, 0, .12); }
</style>
