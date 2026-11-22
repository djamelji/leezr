<script setup>
// Leezr 5.0 (L0, ADR-855) — page hero. Two variants of the SAME identity:
//   cockpit   (Platform, V35-inspired): typographic greeting, live context chips, period + report actions.
//   workspace (Company, V22-inspired): deep brand band, team presence, the day's priority and primary actions.
const props = defineProps({
  variant: { type: String, default: 'cockpit' },
  eyebrow: { type: String, default: '' },
  greeting: { type: String, required: true },
  name: { type: String, required: true },
  subtitle: { type: String, default: '' },
  chips: { type: Array, default: () => [] }, // [{ icon, text, color }]
  people: { type: Array, default: () => [] }, // initials for the presence stack
  progress: { type: Number, default: null }, // 0..100, workspace ring
  progressLabel: { type: String, default: '' },
})
</script>

<template>
  <section
    class="lz-hero"
    :class="`lz-hero--${variant}`"
  >
    <div class="lz-hero__main">
      <div
        v-if="chips.length"
        class="d-flex flex-wrap gap-2 mb-3"
      >
        <VChip
          v-for="c in chips"
          :key="c.text"
          size="small"
          :color="variant === 'workspace' ? 'white' : (c.color || 'primary')"
          variant="tonal"
          class="lz-hero__chip"
        >
          <span
            v-if="c.live"
            class="lzr-live-dot me-2"
          />
          <VIcon
            v-else-if="c.icon"
            start
            size="14"
            :icon="c.icon"
          />{{ c.text }}
        </VChip>
      </div>
      <h1 class="lz-hero__title">
        {{ greeting }}<br><span class="lz-hero__accent">{{ name }}</span>
      </h1>
      <p
        v-if="subtitle"
        class="lz-hero__subtitle"
      >
        {{ subtitle }}
      </p>
      <div class="d-flex flex-wrap gap-2 mt-4">
        <slot name="actions" />
      </div>
    </div>

    <div
      v-if="variant === 'workspace' && (progress !== null || people.length)"
      class="lz-hero__aside"
    >
      <div
        v-if="progress !== null"
        class="lz-ring"
      >
        <VProgressCircular
          :model-value="progress"
          size="132"
          width="10"
          color="white"
          bg-color="rgba(255,255,255,.18)"
        >
          <div class="text-center">
            <div class="lz-ring__value">
              {{ progress }} %
            </div>
            <div class="lz-ring__label">
              {{ progressLabel }}
            </div>
          </div>
        </VProgressCircular>
      </div>
      <div
        v-if="people.length"
        class="lz-stack"
      >
        <VAvatar
          v-for="(p, i) in people.slice(0, 5)"
          :key="p"
          size="34"
          :color="['primary', 'success', 'warning', 'info', 'error'][i % 5]"
          class="lz-stack__item"
        >
          <span class="text-caption font-weight-bold">{{ p }}</span>
        </VAvatar>
        <span
          v-if="people.length > 5"
          class="lz-stack__more"
        >+{{ people.length - 5 }}</span>
      </div>
    </div>
    <slot name="aside" />
  </section>
</template>

<style scoped>
.lz-hero { position: relative; display: flex; gap: 1.5rem; align-items: center; border-radius: 20px; padding: 1.75rem 2rem; overflow: hidden; animation: lz-rise .5s cubic-bezier(.22, 1, .36, 1) both; }
.lz-hero__main { flex: 1 1 auto; min-inline-size: 0; position: relative; z-index: 1; }
.lz-hero__title { font-size: clamp(2rem, 3.6vw, 3rem); line-height: 1.05; font-weight: 700; letter-spacing: -.035em; margin: 0; }
.lz-hero__accent { font-family: Georgia, 'Times New Roman', serif; font-style: italic; font-weight: 400; letter-spacing: -.01em; }
.lz-hero__subtitle { margin: .75rem 0 0; font-size: 1rem; max-inline-size: 60ch; }

/* cockpit — light, typographic, airy (V35) */
.lz-hero--cockpit { padding-inline: .25rem; padding-block: .5rem 1rem; border-radius: 0; animation-duration: .4s; }
.lz-hero--cockpit .lz-hero__accent { color: rgb(var(--v-theme-primary)); }
.lz-hero--cockpit .lz-hero__subtitle { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }

/* workspace — deep brand band (V22) */
.lz-hero--workspace {
  color: #fff;
  background:
    radial-gradient(120% 140% at 100% 0%, rgba(255, 255, 255, .16) 0%, transparent 55%),
    linear-gradient(135deg, rgb(var(--v-theme-primary)) 0%, color-mix(in srgb, rgb(var(--v-theme-primary)) 62%, #0b1530) 100%);
  box-shadow: 0 18px 40px -18px rgba(var(--v-theme-primary), .55);
}
.lz-hero--workspace .lz-hero__subtitle { color: rgba(255, 255, 255, .82); }
.lz-hero__aside { display: flex; flex-direction: column; align-items: center; gap: 1rem; flex: 0 0 auto; }
.lz-ring__value { font-size: 1.625rem; font-weight: 700; color: #fff; line-height: 1; }
.lz-ring__label { font-size: .75rem; color: rgba(255, 255, 255, .8); margin-block-start: .25rem; max-inline-size: 90px; }
.lz-stack { display: flex; align-items: center; }
.lz-stack__item { border: 2px solid rgba(255, 255, 255, .9); margin-inline-start: -8px; }
.lz-stack__more { margin-inline-start: .5rem; font-size: .8125rem; color: rgba(255, 255, 255, .85); }


@keyframes lz-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

@media (max-width: 700px) {
  .lz-hero { flex-direction: column; align-items: stretch; padding: 1.25rem; }
  .lz-hero__aside { flex-direction: row; justify-content: space-between; }
}
@media (prefers-reduced-motion: reduce) {
  .lz-hero { animation: none; }
}
</style>
