<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Facturé ce mois (HT) » KPI for billing.revenue_mtd (view_billing). Amount in cents,
// from the billing dashboard read service (batch dataset) : ledger `invoice_issued` REVENUE credits since the 1st.
// ADR-862 : it is INVOICED, never « collected » (the L1 label said « Encaissé » — wrong, see ADR-841).
import AppKpiTile from '@/components/app/AppKpiTile.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const multi = computed(() => d.value.currency === 'MULTI')
</script>

<template>
  <AppKpiTile
    bare
    data-test="cockpit-invoiced-mtd"
    :label="t('cockpit.collected.label')"
    icon="tabler-cash"
    color="success"
    :value="d.error || multi ? null : (d.revenue ?? null)"
    format="money"
    :currency="d.currency || 'EUR'"
    :context="t('cockpit.collected.context')"
    :empty-hint="multi ? t('cockpit.collected.multi') : t('cockpit.unavailable')"
    :to="{ name: 'platform-billing-tab', params: { tab: 'invoices' } }"
  />
</template>
