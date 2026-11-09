<script setup>
// ADR-746 (P2) — « Sécurité » (platform). Données réelles (SecurityAlert).
import { computed } from 'vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const open = computed(() => d.value.open ?? 0)
const critical = computed(() => d.value.critical ?? 0)
const clear = computed(() => open.value === 0)
const tone = computed(() => critical.value > 0 ? 'error' : open.value > 0 ? 'warning' : 'success')
</script>

<template>
  <div class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-2 flex-shrink-0">
      <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platform.widgets.securityOverview') }}</div>
      <VAvatar :color="tone" variant="tonal" size="40" rounded><VIcon icon="tabler-shield-lock" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />
    <div v-else-if="clear" class="flex-grow-1 d-flex align-center gap-2 text-success">
      <VIcon icon="tabler-shield-check" size="22" /><span class="text-body-1 font-weight-medium">{{ t('platform.widgets.securityClear') }}</span>
    </div>

    <div v-else class="flex-grow-1 d-flex align-center gap-5">
      <div>
        <div class="text-h3 font-weight-medium" :class="critical > 0 ? 'text-error' : 'text-high-emphasis'">{{ open }}</div>
        <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.alertsOpen') }}</div>
      </div>
      <VDivider vertical class="my-2" />
      <div class="d-flex flex-column gap-2">
        <div class="d-flex align-center gap-2">
          <VIcon icon="tabler-alert-octagon" :color="critical > 0 ? 'error' : 'disabled'" size="18" />
          <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.criticalN', { n: critical }) }}</span>
        </div>
        <div class="d-flex align-center gap-2">
          <VIcon icon="tabler-alert-triangle" :color="(d.high ?? 0) > 0 ? 'warning' : 'disabled'" size="18" />
          <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.highMediumN', { high: d.high ?? 0, medium: d.medium ?? 0 }) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
