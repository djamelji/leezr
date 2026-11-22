<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Paiements échoués (7 j) » KPI for billing.failed_payments_7d (view_billing).
import AppKpiTile from '@/components/app/AppKpiTile.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
</script>

<template>
  <AppKpiTile
    bare
    :label="t('cockpit.failed.label')"
    icon="tabler-credit-card-off"
    :color="(d.count ?? 0) > 0 ? 'error' : 'success'"
    :value="d.error ? null : (d.count ?? null)"
    :context="(d.count ?? 0) > 0 ? t('cockpit.failed.contextSome') : t('cockpit.failed.contextNone')"
    :empty-hint="t('cockpit.unavailable')"
    :to="{ name: 'platform-billing-tab', params: { tab: 'payments' } }"
  />
</template>
