<script setup>
// ADR-745 — panneau composite « Documents à régulariser ».
// Lit le store compliance existant (client-resolved). Manquants / expirés + types
// les plus exposés avec taux de conformité. Aucune donnée inventée, aucun backend touché.
import { computed } from 'vue'
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const store = useCompanyComplianceStore()

const missing = computed(() => store.missingCount ?? 0)
const expired = computed(() => store.expiredCount ?? 0)
const allClear = computed(() => missing.value === 0 && expired.value === 0)

// Types les plus exposés (manquants + expirés), top 4.
const topTypes = computed(() => {
  const rows = (store.byType ?? []).filter(r => (r.missing + r.expired) > 0)
  return [...rows].sort((a, b) => (b.missing + b.expired) - (a.missing + a.expired)).slice(0, 4)
})
</script>

<template>
  <div class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-2 flex-shrink-0">
      <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('compliance.widgets.detail') }}</div>
      <VAvatar :color="allClear ? 'success' : 'warning'" variant="tonal" size="40" rounded><VIcon icon="tabler-file-alert" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="store.isLoading" type="text" class="bg-transparent" />
    <div v-else-if="!store.hasData" class="flex-grow-1 d-flex align-center justify-center text-medium-emphasis text-body-2">{{ t('compliance.noData') }}</div>
    <div v-else-if="allClear" class="flex-grow-1 d-flex align-center gap-2 text-success">
      <VIcon icon="tabler-circle-check" size="22" />
      <span class="text-body-1 font-weight-medium">{{ t('compliance.widgets.allClear') }}</span>
    </div>

    <div v-else class="flex-grow-1 d-flex flex-column">
      <!-- Totaux manquants / expirés -->
      <div class="d-flex gap-6 mb-3 flex-shrink-0">
        <div>
          <div class="text-h5 font-weight-medium" :class="missing > 0 ? 'text-high-emphasis' : 'text-disabled'">{{ missing }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('compliance.statusMissing') }}</div>
        </div>
        <div>
          <div class="text-h5 font-weight-medium" :class="expired > 0 ? 'text-error' : 'text-disabled'">{{ expired }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('compliance.statusExpired') }}</div>
        </div>
      </div>

      <!-- Types les plus exposés -->
      <div class="text-caption text-medium-emphasis mb-1 flex-shrink-0">{{ t('compliance.widgets.topTypes') }}</div>
      <div class="d-flex flex-column gap-2">
        <div v-for="ty in topTypes" :key="ty.code">
          <div class="d-flex align-center justify-space-between mb-1">
            <span class="text-body-2 text-high-emphasis text-truncate">{{ ty.label }}</span>
            <span class="text-caption text-medium-emphasis flex-shrink-0">{{ Math.round(ty.rate) }}%</span>
          </div>
          <VProgressLinear :model-value="ty.rate" :color="ty.rate < 50 ? 'error' : ty.rate < 80 ? 'warning' : 'success'" height="6" rounded bg-color="rgba(var(--v-theme-on-surface),0.08)" :aria-label="ty.label" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
