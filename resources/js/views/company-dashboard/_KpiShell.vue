<script setup>
// ADR-745 → ADR-856 (Leezr 5.0 L4) — KPI shell of the Company widgets, now the shared AppKpiTile (L0) : same API
// (icon, color, label, value, caption, loading, error, tone, #action) + to / linkLabel / breakdown / spark / delta.
// The tile recomposes itself with the real size of the grid cell ; on error no value is ever shown.
import AppKpiTile from '@/components/app/AppKpiTile.vue'

defineProps({
  icon: { type: String, default: 'tabler-point' },
  color: { type: String, default: 'primary' },
  label: { type: String, default: '' },
  value: { type: [String, Number], default: null },
  caption: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
  tone: { type: String, default: '' }, // kept for compatibility : the colour now carries the state
  to: { type: [String, Object], default: null },
  linkLabel: { type: String, default: '' },
  breakdown: { type: Array, default: () => [] },
  spark: { type: Array, default: () => [] },
  sparkType: { type: String, default: 'bar' },
  delta: { type: Number, default: null },
  deltaLabel: { type: String, default: '' },
  emptyHint: { type: String, default: '' },
})
</script>

<template>
  <AppKpiTile
    bare
    :icon="icon"
    :color="color"
    :label="label"
    :value="value"
    :context="caption"
    :loading="loading"
    :error="error"
    :to="to"
    :link-label="linkLabel"
    :breakdown="breakdown"
    :spark="spark"
    :spark-type="sparkType"
    :delta="delta"
    :delta-label="deltaLabel"
    :empty-hint="emptyHint"
  >
    <template
      v-if="$slots.action"
      #action
    >
      <slot name="action" />
    </template>
  </AppKpiTile>
</template>
