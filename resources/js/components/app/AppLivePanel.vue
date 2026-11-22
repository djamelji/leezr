<script setup>
// Leezr 5.0 (L0, ADR-855) — dark supervision surface (V35 « monitor » analogue). ONE per page at most.
// Generic : eyebrow, title, subtitle, a semantic status chip, free content (default slot) and a footer slot.
// No fake activity : the eyebrow dot pulses only when `live` is true (data really refreshed on a schedule).
defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  status: { type: String, default: 'ok' }, // ok | warning | critical | unknown
  statusText: { type: String, default: '' },
  live: { type: Boolean, default: false },
})
const COLOR = { ok: 'success', healthy: 'success', warning: 'warning', critical: 'error', unknown: 'secondary' }
</script>

<template>
  <div class="app-live h-100">
    <div class="app-live__inner">
      <div class="d-flex align-start gap-3">
        <div class="flex-grow-1 min-w-0">
          <div
            v-if="eyebrow"
            class="app-live__eyebrow"
          >
            <span
              class="app-live__dot"
              :class="[`bg-${COLOR[status] || 'secondary'}`, { 'app-live__dot--live': live }]"
            />{{ eyebrow }}
          </div>
          <div class="app-live__title text-truncate">
            {{ title }}
          </div>
          <div
            v-if="subtitle"
            class="app-live__muted text-truncate"
          >
            {{ subtitle }}
          </div>
        </div>
        <VChip
          v-if="statusText"
          class="flex-shrink-0"
          :color="COLOR[status] || 'secondary'"
          size="small"
          variant="flat"
          label
        >
          {{ statusText }}
        </VChip>
      </div>
      <div class="app-live__body">
        <slot />
      </div>
      <slot name="footer" />
    </div>
  </div>
</template>

<style scoped>
.app-live {
  color: #fff;
  border-radius: inherit;
  background:
    radial-gradient(120% 80% at 100% 0%, rgba(126, 240, 199, .10) 0%, transparent 60%),
    linear-gradient(160deg, #1b2140 0%, #121631 100%);
}
.app-live__inner { block-size: 100%; display: flex; flex-direction: column; gap: 1rem; padding: 1.25rem; }
.app-live__body { flex: 1 1 auto; min-block-size: 0; overflow: hidden; }
.app-live__eyebrow { display: flex; align-items: center; gap: .5rem; font-size: .6875rem; letter-spacing: .12em; font-weight: 700; color: #7ef0c7; text-transform: uppercase; }
.app-live__dot { inline-size: 8px; block-size: 8px; border-radius: 50%; }
.app-live__dot--live { animation: app-live-blink 1.6s ease-in-out infinite; }
.app-live__title { font-size: 1.25rem; font-weight: 700; letter-spacing: -.02em; margin-block-start: .25rem; }
.app-live__muted { color: rgba(255, 255, 255, .62); font-size: .8125rem; }
@keyframes app-live-blink { 50% { opacity: .35; } }
@media (prefers-reduced-motion: reduce) { .app-live__dot--live { animation: none; } }
</style>
