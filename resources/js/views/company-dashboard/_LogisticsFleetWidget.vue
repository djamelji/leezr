<script setup>
// ADR-856 (Leezr 5.0 L4) — « Flotte & livraisons » (logistics.fleet, shipments.view). Real data : drivers_active,
// completed_today, scheduled_today, next. KPI family with progress breakdown ; next delivery shown when large.
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'company' }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const has = computed(() => d.value.drivers_active !== undefined)
const completed = computed(() => d.value.completed_today ?? 0)
const scheduled = computed(() => d.value.scheduled_today ?? 0)

const nextWhen = computed(() => {
  const n = d.value.next
  if (!n?.scheduled_at) return ''
  try { return new Date(n.scheduled_at).toLocaleString('fr-FR', { weekday: 'short', hour: '2-digit', minute: '2-digit' }) }
  catch { return '' }
})

const breakdown = computed(() => [
  { label: t('l4.kpi.done'), value: `${completed.value} / ${scheduled.value}`, tone: completed.value && completed.value === scheduled.value ? 'success' : '' },
  { label: t('shipments.widgets.nextDelivery'), value: nextWhen.value || '—' },
])
</script>

<template>
  <KpiShell
    icon="tabler-steering-wheel"
    color="success"
    :label="t('shipments.widgets.driversActive')"
    :value="has ? d.drivers_active : null"
    :caption="has ? `${t('shipments.widgets.deliveriesToday')} : ${completed} / ${scheduled}` : ''"
    :empty-hint="t('shipments.widgets.noNextDelivery')"
    :breakdown="has ? breakdown : []"
    :loading="loading"
    :error="!!d.error"
    :to="{ name: 'company-shipments' }"
    :link-label="t('l4.kpi.seeShipments')"
  />
</template>
