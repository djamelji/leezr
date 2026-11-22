<script setup>
// Leezr 5.0 DESIGN LAB — dark live supervision panel (prototype; V35 « monitor » analogue for a SaaS operator).
// Shows what Leezr really measures today: queue backlog, failed jobs, scheduler heartbeat, API latency, error rate.
const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  status: { type: String, default: 'ok' }, // ok | degraded | down
  series: { type: Array, default: () => [] }, // latency ms
  metrics: { type: Array, default: () => [] }, // [{ label, value, unit, tone, icon }]
  tabs: { type: Array, default: () => [] },
  chartLabel: { type: String, default: 'Latence' },
  chartNote: { type: String, default: '' },
})

const tab = ref(props.tabs[0] || null)
const statusText = computed(() => ({ ok: 'Opérationnel', degraded: 'Dégradé', down: 'Incident' })[props.status])
const statusColor = computed(() => ({ ok: 'success', degraded: 'warning', down: 'error' })[props.status])

const chart = computed(() => ({
  chart: { sparkline: { enabled: true }, animations: { enabled: true, easing: 'linear', dynamicAnimation: { speed: 900 } } },
  stroke: { curve: 'smooth', width: 2.5 },
  fill: { type: 'gradient', gradient: { opacityFrom: .35, opacityTo: 0 } },
  colors: ['#7ef0c7'],
  grid: { padding: { top: 6, bottom: 6 } },
  tooltip: { theme: 'dark', x: { show: false }, y: { formatter: v => `${v} ms`, title: { formatter: () => props.chartLabel } } },
}))
</script>

<template>
  <VCard
    class="lz-live h-100"
    theme="dark"
    rounded="xl"
  >
    <div class="pa-5 d-flex flex-column h-100 gap-4">
      <div class="d-flex align-start gap-3">
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex align-center gap-2 text-caption lz-live__eyebrow">
            <span
              class="lz-live__dot"
              :class="`bg-${statusColor}`"
            />EN DIRECT
          </div>
          <div class="text-h5 font-weight-bold mt-1">
            {{ title }}
          </div>
          <div class="text-body-2 lz-live__muted">
            {{ subtitle }}
          </div>
        </div>
        <VChip
          class="flex-shrink-0"
          :color="statusColor"
          size="small"
          variant="tonal"
          label
        >
          {{ statusText }}
        </VChip>
      </div>

      <div
        v-if="tabs.length"
        class="lz-live__tabs"
      >
        <button
          v-for="t in tabs"
          :key="t"
          type="button"
          class="lz-live__tab"
          :class="{ 'lz-live__tab--on': tab === t }"
          @click="tab = t"
        >
          {{ t }}
        </button>
      </div>

      <div class="lz-live__chart">
        <div class="d-flex justify-space-between text-caption lz-live__muted px-1">
          <span>{{ chartLabel }}</span><span>{{ chartNote }}</span>
        </div>
        <VueApexCharts
          type="area"
          height="110"
          :options="chart"
          :series="[{ name: 'latence', data: series }]"
        />
      </div>

      <div class="lz-live__grid">
        <div
          v-for="m in metrics"
          :key="m.label"
          class="lz-live__metric"
        >
          <div class="d-flex align-center gap-1 text-caption lz-live__muted">
            <VIcon
              :icon="m.icon"
              size="14"
              :color="m.tone"
            />{{ m.label }}
          </div>
          <div
            class="lz-live__value"
            :class="m.tone ? `text-${m.tone}` : ''"
          >
            {{ m.value }}<small v-if="m.unit">{{ m.unit }}</small>
          </div>
        </div>
      </div>

      <slot name="footer" />
    </div>
  </VCard>
</template>

<style scoped>
.lz-live {
  background:
    radial-gradient(120% 80% at 100% 0%, rgba(126, 240, 199, .10) 0%, transparent 60%),
    linear-gradient(160deg, #1b2140 0%, #121631 100%) !important;
  border: 1px solid rgba(255, 255, 255, .06);
  box-shadow: 0 24px 48px -24px rgba(10, 14, 40, .6) !important;
}
.lz-live__eyebrow { letter-spacing: .12em; font-weight: 700; color: #7ef0c7; }
.lz-live__dot { inline-size: 8px; block-size: 8px; border-radius: 50%; animation: lz-blink 1.6s ease-in-out infinite; }
.lz-live__muted { color: rgba(255, 255, 255, .6); }
.lz-live__tabs { display: inline-flex; gap: .25rem; padding: .25rem; border-radius: 999px; background: rgba(255, 255, 255, .06); align-self: flex-start; }
.lz-live__tab { font-size: .75rem; padding: .25rem .75rem; border-radius: 999px; color: rgba(255, 255, 255, .7); transition: background .2s, color .2s; }
.lz-live__tab--on { background: #fff; color: #121631; font-weight: 600; }
.lz-live__chart { border-radius: 14px; background: rgba(0, 0, 0, .22); padding: .75rem .5rem .25rem; }
.lz-live__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
.lz-live__metric { border-radius: 12px; background: rgba(255, 255, 255, .04); padding: .625rem .75rem; }
.lz-live__value { font-size: 1.5rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.2; }
.lz-live__value small { font-size: .75rem; font-weight: 500; margin-inline-start: .125rem; opacity: .7; }
@keyframes lz-blink { 50% { opacity: .35; } }
@media (prefers-reduced-motion: reduce) { .lz-live__dot { animation: none; } }
</style>
