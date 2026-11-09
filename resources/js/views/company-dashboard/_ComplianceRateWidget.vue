<script setup>
// ADR-744 (P1) — Conformité documentaire re-visualisée en JAUGE d'alerte (Lzr).
// Remplace le chiffre plat (« 3% » rouge) par une jauge radiale à seuils sémantiques.
// Données réelles via le store compliance existant (client-resolved, inchangé).
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
const compliant = computed(() => (store.validCount ?? 0) + (store.expiringSoonCount ?? 0))
const gaugeH = computed(() => (props.viewport?.density === 'S' ? 120 : props.viewport?.density === 'M' ? 150 : 180))
</script>

<template>
  <div class="widget-root d-flex flex-column h-100">
    <div class="d-flex align-center gap-2 mb-1 flex-shrink-0">
      <VAvatar color="error" variant="tonal" size="34" rounded>
        <VIcon icon="tabler-shield-check" size="20" />
      </VAvatar>
      <span class="text-medium-emphasis text-body-2 text-truncate">{{ t('compliance.complianceRate') }}</span>
    </div>

    <div class="flex-grow-1 d-flex flex-column align-center justify-center">
      <VSkeletonLoader v-if="store.isLoading" type="image" width="150" class="bg-transparent" />
      <div v-else-if="!store.hasData" class="text-medium-emphasis text-body-2">{{ t('compliance.noData') }}</div>
      <template v-else>
        <LzrGauge :value="rate" :label="t('compliance.compliant')" invert :danger="50" :warn="80" :height="gaugeH" />
        <div class="text-center text-caption text-medium-emphasis mt-n2">
          {{ compliant }} / {{ store.totalSlots }} {{ t('compliance.total') }}
        </div>
      </template>
    </div>
  </div>
</template>
