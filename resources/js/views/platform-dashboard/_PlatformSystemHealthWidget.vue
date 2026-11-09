<script setup>
// ADR-746 (P2) — « Santé des services » (platform). Données réelles (SystemHealthService).
import { computed } from 'vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const COLOR = { healthy: 'success', warning: 'warning', critical: 'error' }
const ICON = { healthy: 'tabler-circle-check', warning: 'tabler-alert-triangle', critical: 'tabler-alert-octagon' }
const status = computed(() => d.value.status ?? 'healthy')
const sections = computed(() => d.value.sections ?? [])
</script>

<template>
  <div class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-2 flex-shrink-0">
      <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platform.widgets.systemHealth') }}</div>
      <VAvatar :color="COLOR[status] || 'secondary'" variant="tonal" size="40" rounded><VIcon :icon="ICON[status] || 'tabler-heartbeat'" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />

    <div v-else class="flex-grow-1 d-flex flex-column">
      <div class="d-flex align-center gap-2 mb-3">
        <VChip :color="COLOR[status] || 'secondary'" size="small" variant="tonal" label>{{ t('platform.widgets.health.' + status) }}</VChip>
      </div>
      <div class="d-flex flex-column gap-2">
        <div v-for="s in sections" :key="s.key" class="d-flex align-center gap-2 py-1">
          <VIcon :icon="ICON[s.status] || 'tabler-point'" :color="COLOR[s.status] || 'secondary'" size="18" />
          <span class="text-body-2 text-high-emphasis flex-grow-1">{{ s.label }}</span>
          <span class="text-caption text-medium-emphasis">{{ t('platform.widgets.health.' + s.status) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
