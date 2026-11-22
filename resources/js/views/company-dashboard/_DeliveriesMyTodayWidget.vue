<script setup>
// ADR-856 (Leezr 5.0 L4) — « deliveries.my_today » (shipments.view_own). Real data : { data: { count } }. KPI family (AppKpiTile via KpiShell) :
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
    icon="tabler-package"
    color="primary"
    :label="t('shipments.widgets.myToday')"
    :value="count"
    :caption="count === null ? '' : t('shipments.widgets.myTodayDesc')"
    :empty-hint="t('l4.kpi.noShipmentData')"
    :loading="loading"
    :error="!!d.error"
    :to="{ name: 'company-my-deliveries' }"
    :link-label="t('l4.kpi.seeMyDeliveries')"
  />
</template>
