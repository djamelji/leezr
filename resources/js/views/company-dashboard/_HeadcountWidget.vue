<script setup>
// ADR-745 — KPI compact Effectifs. Données réelles (active/on_leave/total).
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const active = computed(() => d.value.active ?? 0)
const onLeave = computed(() => d.value.on_leave ?? 0)
const caption = computed(() => onLeave.value > 0
  ? t('workforce.widgets.headcountCaption', { n: onLeave.value })
  : t('workforce.widgets.headcountAllPresent'))
</script>

<template>
  <KpiShell
    icon="tabler-users-group" color="primary"
    :label="t('workforce.widgets.headcountLabel')"
    :value="active" :caption="caption"
    :loading="loading" :error="d.error"
  />
</template>
