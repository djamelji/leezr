<script setup>
// Leezr 5.0 (L0, ADR-855) — short choice among 2–4 options (period, view, audience). Replaces the ad-hoc
// « lz-seg » controls and the tall VBtnToggle in dense headers. Keyboard: native buttons, aria-pressed.
const props = defineProps({
  items: { type: Array, required: true }, // [{ value, label, icon? }] or strings
  size: { type: String, default: 'default' }, // default | small
  ariaLabel: { type: String, default: '' },
})

const model = defineModel({ type: [String, Number, Boolean], default: null })
const norm = computed(() => props.items.map(i => (typeof i === 'object' ? i : { value: i, label: i })))
</script>

<template>
  <div
    class="app-seg"
    :class="`app-seg--${size}`"
    role="group"
    :aria-label="ariaLabel || undefined"
  >
    <button
      v-for="i in norm"
      :key="String(i.value)"
      type="button"
      class="app-seg__btn"
      :class="{ 'app-seg__btn--on': model === i.value }"
      :aria-pressed="model === i.value"
      :aria-label="i.label || undefined"
      @click="model = i.value"
    >
      <VIcon
        v-if="i.icon"
        :icon="i.icon"
        size="17"
      />
      <span v-if="i.label && !i.iconOnly">{{ i.label }}</span>
    </button>
  </div>
</template>

<style scoped>
.app-seg { display: inline-flex; flex-shrink: 0; padding: 3px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), .06); }
.app-seg__btn { display: inline-flex; align-items: center; gap: .375rem; font-size: .8125rem; padding: .3rem .75rem; border-radius: 8px; color: rgba(var(--v-theme-on-surface), .7); white-space: nowrap; transition: background .18s, color .18s, box-shadow .18s; }
.app-seg--small .app-seg__btn { font-size: .75rem; padding: .25rem .625rem; }
.app-seg__btn:hover { color: rgb(var(--v-theme-on-surface)); }
.app-seg__btn:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 1px; }
.app-seg__btn--on { background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-on-surface)); font-weight: 600; box-shadow: 0 1px 3px rgba(0, 0, 0, .12); }
.v-theme--dark .app-seg__btn--on { background: rgba(255, 255, 255, .12); }
@media (prefers-reduced-motion: reduce) { .app-seg__btn { transition: none; } }
</style>
