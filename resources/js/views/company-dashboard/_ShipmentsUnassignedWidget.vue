<script setup>
// ADR-856 (Leezr 5.0 L4) — « shipments.unassigned » (shipments.assign). Real data : { data: { count } }. KPI family (AppKpiTile via KpiShell) :
// recomposed with the real size ; error never shows a number ; the tile opens the business page.
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'company' }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const count = computed(() => d.value.data?.count ?? null)
</script>

<template>
  <KpiShell
    icon="tabler-user-question"
    :color="count > 0 ? 'warning' : 'success'"
    :label="t('shipments.widgets.unassigned')"
    :value="count"
    :caption="count === null ? '' : t('shipments.widgets.unassignedDesc')"
    :empty-hint="t('l4.kpi.noShipmentData')"
    :loading="loading"
    :error="!!d.error"
    :to="{ name: 'company-shipments' }"
    :link-label="t('l4.kpi.assign')"
  />
</template>
