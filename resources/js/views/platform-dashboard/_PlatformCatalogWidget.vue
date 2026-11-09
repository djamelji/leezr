<script setup>
// ADR-746 (P2) — « Catalogue & adoption » (platform). Données réelles (CompanyModule, Jobdomain).
import { computed } from 'vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const top = computed(() => d.value.top_modules ?? [])
const maxC = computed(() => Math.max(1, ...top.value.map(m => m.companies)))
const jobdomains = computed(() => d.value.jobdomains ?? [])
</script>

<template>
  <div class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-2 flex-shrink-0">
      <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platform.widgets.catalogOverview') }}</div>
      <VAvatar color="primary" variant="tonal" size="40" rounded><VIcon icon="tabler-apps" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />

    <div v-else class="flex-grow-1 d-flex flex-column">
      <div class="text-caption text-medium-emphasis mb-1 flex-shrink-0">{{ t('platform.widgets.topModules') }}</div>
      <div class="d-flex flex-column gap-2">
        <div v-for="m in top" :key="m.key">
          <div class="d-flex align-center justify-space-between mb-1">
            <span class="text-body-2 text-high-emphasis text-truncate">{{ m.key }}</span>
            <span class="text-caption text-medium-emphasis flex-shrink-0">{{ t('platform.widgets.companiesN', { n: m.companies }) }}</span>
          </div>
          <VProgressLinear :model-value="(m.companies / maxC) * 100" color="primary" height="6" rounded bg-color="rgba(var(--v-theme-on-surface),0.08)" :aria-label="m.key" />
        </div>
      </div>
      <VDivider class="my-2" />
      <div class="d-flex flex-wrap gap-2">
        <VChip v-for="j in jobdomains" :key="j.key" size="small" variant="tonal" color="secondary" label>
          {{ j.key }} · {{ j.companies }}
        </VChip>
      </div>
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
