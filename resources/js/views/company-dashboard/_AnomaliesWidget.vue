<script setup>
// ADR-745 — KPI compact Anomalies de pointage. Données réelles (anomalies/incomplete/not_clocked).
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const anomalies = computed(() => d.value.anomalies ?? 0)
const incomplete = computed(() => d.value.incomplete ?? 0)
const notClocked = computed(() => d.value.not_clocked ?? 0)
const ok = computed(() => anomalies.value === 0)
const caption = computed(() => ok.value
  ? t('workforce.widgets.anomaliesNone')
  : t('workforce.widgets.anomaliesBreakdown', { incomplete: incomplete.value, notClocked: notClocked.value }))
</script>

<template>
  <KpiShell
    icon="tabler-alert-triangle" :color="ok ? 'success' : 'error'"
    :label="t('workforce.widgets.anomaliesTitle')"
    :value="anomalies" :tone="ok ? 'positive' : ''"
    :caption="caption"
    :loading="loading" :error="d.error"
  />
</template>
