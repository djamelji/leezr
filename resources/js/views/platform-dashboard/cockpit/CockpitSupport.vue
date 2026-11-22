<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Support » KPI for platform.support_overview (manage_support).
import AppKpiTile from '@/components/app/AppKpiTile.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
</script>

<template>
  <AppKpiTile
    bare
    :label="t('cockpit.support.label')"
    icon="tabler-headset"
    :color="(d.unassigned ?? 0) > 0 ? 'warning' : 'success'"
    :value="d.error ? null : (d.open ?? null)"
    :context="t('cockpit.support.context', { u: d.unassigned ?? 0, r: d.resolved ?? 0 })"
    :empty-hint="t('cockpit.unavailable')"
    :to="{ name: 'platform-communications-tab', params: { tab: 'support' } }"
  />
</template>
