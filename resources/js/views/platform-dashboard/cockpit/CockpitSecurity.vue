<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Sécurité » KPI for platform.security_overview (security.alerts.view).
import AppKpiTile from '@/components/app/AppKpiTile.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
</script>

<template>
  <AppKpiTile
    bare
    :label="t('cockpit.security.label')"
    icon="tabler-shield-lock"
    :color="(d.critical ?? 0) > 0 ? 'error' : (d.high ?? 0) > 0 ? 'warning' : 'success'"
    :value="d.error ? null : (d.open ?? null)"
    :context="t('cockpit.security.context', { c: d.critical ?? 0, h: d.high ?? 0, m: d.medium ?? 0 })"
    :empty-hint="t('cockpit.unavailable')"
    :to="{ name: 'platform-operations-tab', params: { tab: 'security' } }"
  />
</template>
