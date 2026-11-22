<script setup>
// ADR-745 → ADR-856 — « Anomalies de pointage » (workforce.anomalies). Real data : anomalies / incomplete / not_clocked.
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const has = computed(() => d.value.anomalies !== undefined)
const ok = computed(() => (d.value.anomalies ?? 0) === 0)

const caption = computed(() => ok.value
  ? t('workforce.widgets.anomaliesNone')
  : t('workforce.widgets.anomaliesBreakdown', { incomplete: d.value.incomplete ?? 0, notClocked: d.value.not_clocked ?? 0 }))

const breakdown = computed(() => [
  { label: t('l4.kpi.incomplete'), value: d.value.incomplete ?? 0, tone: (d.value.incomplete ?? 0) ? 'warning' : '' },
  { label: t('l4.kpi.notClocked'), value: d.value.not_clocked ?? 0, tone: (d.value.not_clocked ?? 0) ? 'error' : '' },
])
</script>

<template>
  <KpiShell
    icon="tabler-alert-triangle"
    :color="ok ? 'success' : 'error'"
    :label="t('workforce.widgets.anomaliesTitle')"
    :value="has ? d.anomalies : null"
    :caption="has ? caption : ''"
    :empty-hint="t('l4.kpi.noClockData')"
    :breakdown="breakdown"
    :loading="loading"
    :error="!!d.error"
    :to="{ name: 'company-workforce-time' }"
    :link-label="t('l4.kpi.seeClock')"
  />
</template>
