<script setup>
// ADR-745 — panneau hero « Conformité documentaire ».
// Composite : jauge radiale + ventilation des 4 statuts + rôle le plus exposé.
// Pattern Vuexy AnalyticsSupportTracker (radialBar + liste) sur données réelles.
import { computed } from 'vue'
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'
import LzrGauge from '@/core/components/LzrGauge.vue'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({ density: 'L' }) },
})

const { t } = useI18n()
const store = useCompanyComplianceStore()

const rate = computed(() => Number(store.complianceRate ?? 0))
const gaugeH = computed(() => (props.viewport?.density === 'S' ? 120 : 140))

const breakdown = computed(() => [
  { key: 'valid', color: 'success', icon: 'tabler-circle-check', label: t('compliance.statusValid'), value: store.validCount ?? 0 },
  { key: 'expiring', color: 'warning', icon: 'tabler-clock-exclamation', label: t('compliance.statusExpiring'), value: store.expiringSoonCount ?? 0 },
  { key: 'missing', color: 'secondary', icon: 'tabler-file-off', label: t('compliance.statusMissing'), value: store.missingCount ?? 0 },
  { key: 'expired', color: 'error', icon: 'tabler-alert-triangle', label: t('compliance.statusExpired'), value: store.expiredCount ?? 0 },
])

// Rôle le plus exposé = plus de documents manquants + expirés.
const topExposed = computed(() => {
  const rows = (store.byRole ?? []).filter(r => (r.missing + r.expired) > 0)
  if (!rows.length) return null

  return [...rows].sort((a, b) => (b.missing + b.expired) - (a.missing + a.expired))[0]
})
</script>

<template>
  <div class="widget-root compliance-panel d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
      <div>
        <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('compliance.complianceRate') }}</div>
        <div class="text-caption text-medium-emphasis">{{ store.totalSlots ?? 0 }} {{ t('compliance.total') }}</div>
      </div>
      <VAvatar color="primary" variant="tonal" size="40" rounded>
        <VIcon icon="tabler-shield-check" size="22" />
      </VAvatar>
    </div>

    <VSkeletonLoader v-if="store.isLoading" type="image" class="bg-transparent flex-grow-1" />
    <div v-else-if="!store.hasData" class="flex-grow-1 d-flex align-center justify-center text-medium-emphasis text-body-2">
      {{ t('compliance.noData') }}
    </div>

    <div v-else class="compliance-body flex-grow-1 d-flex flex-column">
      <div class="d-flex align-center justify-center flex-shrink-0">
        <LzrGauge :value="rate" :label="t('compliance.compliant')" invert :danger="50" :warn="80" :height="gaugeH" />
      </div>

      <VDivider class="my-2" />

      <div class="breakdown">
        <div v-for="b in breakdown" :key="b.key" class="d-flex align-center gap-2 py-1">
          <VIcon :icon="b.icon" :color="b.color" size="18" />
          <span class="text-body-2 text-medium-emphasis flex-grow-1">{{ b.label }}</span>
          <span class="text-body-2 font-weight-medium text-high-emphasis">{{ b.value }}</span>
        </div>
      </div>

      <div class="flex-grow-1" />

      <template v-if="topExposed">
        <VDivider class="my-2" />
        <div class="d-flex align-center gap-2">
          <VIcon icon="tabler-user-exclamation" color="error" size="18" />
          <span class="text-caption text-medium-emphasis flex-grow-1 text-truncate">{{ t('compliance.mostExposed') }}</span>
          <span class="text-caption font-weight-medium text-high-emphasis text-truncate">{{ topExposed.role_name || t('compliance.unspecifiedRole') }}</span>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; }
</style>
