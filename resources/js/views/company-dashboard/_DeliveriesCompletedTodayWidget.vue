<script setup>
// ADR-856 (Leezr 5.0 L4) — « deliveries.completed_today » (shipments.view_own). Real data : { data: { completed, total } }.
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'company' }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const completed = computed(() => d.value.data?.completed ?? null)
const total = computed(() => d.value.data?.total ?? 0)

const breakdown = computed(() => [
  { label: t('l4.kpi.done'), value: completed.value ?? 0, tone: 'success' },
  { label: t('l4.kpi.remaining'), value: Math.max(0, total.value - (completed.value ?? 0)), tone: total.value - (completed.value ?? 0) > 0 ? 'primary' : '' },
])
</script>

<template>
  <KpiShell
    icon="tabler-circle-check"
    color="success"
    :label="t('shipments.widgets.completedToday')"
    :value="completed === null ? null : `${completed} / ${total}`"
    :caption="completed === null ? '' : t('shipments.widgets.completedTodayDesc')"
    :empty-hint="t('l4.kpi.noShipmentData')"
    :breakdown="total ? breakdown : []"
    :loading="loading"
    :error="!!d.error"
    :to="{ name: 'company-my-deliveries' }"
    :link-label="t('l4.kpi.seeMyDeliveries')"
  />
</template>
