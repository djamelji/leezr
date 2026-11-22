<script setup>
// ADR-856 (Leezr 5.0 L4) — « Pièces manquantes » (compliance.pending, documents.view). Client resolution : the
// company compliance store (loaded once by the home). KPI family ; opens the register filtered on missing documents.
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'

defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })

const { t } = useI18n()
const store = useCompanyComplianceStore()
const n = computed(() => store.missingCount ?? 0)
</script>

<template>
  <KpiShell
    icon="tabler-file-off"
    :color="n > 0 ? 'warning' : 'success'"
    :label="t('compliance.missing')"
    :value="store.hasData ? n : null"
    :caption="store.hasData ? (n > 0 ? t('compliance.documentsNotUploaded') : t('l4.kpi.allProvided')) : ''"
    :empty-hint="t('compliance.noData')"
    :loading="store.isLoading"
    :error="!!store.error"
    :to="{ name: 'company-documents-tab', params: { tab: 'compliance' }, query: { status: 'missing' } }"
    :link-label="t('l4.kpi.seeDocuments')"
  />
</template>
