<script setup>
// ADR-856 (Leezr 5.0 L4) — « Pièces expirées » (compliance.overdue, documents.view). Client resolution (compliance store).
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'

defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })

const { t } = useI18n()
const store = useCompanyComplianceStore()
const n = computed(() => store.expiredCount ?? 0)

const breakdown = computed(() => [
  { label: t('compliance.statusExpired'), value: n.value, tone: n.value ? 'error' : '' },
  { label: t('compliance.statusExpiring'), value: store.expiringSoonCount ?? 0, tone: (store.expiringSoonCount ?? 0) ? 'warning' : '' },
])
</script>

<template>
  <KpiShell
    icon="tabler-alert-triangle"
    :color="n > 0 ? 'error' : 'success'"
    :label="t('compliance.expired')"
    :value="store.hasData ? n : null"
    :caption="store.hasData ? (n > 0 ? t('compliance.documentsExpired') : t('l4.kpi.noneExpired')) : ''"
    :empty-hint="t('compliance.noData')"
    :breakdown="store.hasData ? breakdown : []"
    :loading="store.isLoading"
    :error="!!store.error"
    :to="{ name: 'company-documents-tab', params: { tab: 'compliance' }, query: { status: 'expired' } }"
    :link-label="t('l4.kpi.seeDocuments')"
  />
</template>
