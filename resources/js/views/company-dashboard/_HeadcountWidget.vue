<script setup>
// ADR-745 → ADR-856 — « Effectifs » (workforce.headcount, workforce.view). Real data : active / on_leave / total.
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const has = computed(() => d.value.active !== undefined)
const onLeave = computed(() => d.value.on_leave ?? 0)
const caption = computed(() => onLeave.value > 0 ? t('workforce.widgets.headcountCaption', { n: onLeave.value }) : t('workforce.widgets.headcountAllPresent'))

const breakdown = computed(() => [
  { label: t('l4.kpi.active'), value: d.value.active ?? 0, tone: 'success' },
  { label: t('l4.kpi.onLeave'), value: onLeave.value, tone: onLeave.value ? 'info' : '' },
  { label: t('l4.kpi.total'), value: d.value.total ?? 0 },
])
</script>

<template>
  <KpiShell
    icon="tabler-users-group"
    color="primary"
    :label="t('workforce.widgets.headcountLabel')"
    :value="has ? d.active : null"
    :caption="has ? caption : ''"
    :empty-hint="t('l4.kpi.noEmployee')"
    :breakdown="breakdown"
    :loading="loading"
    :error="!!d.error"
    :to="{ name: 'company-people' }"
    :link-label="t('l4.kpi.seePeople')"
  />
</template>
