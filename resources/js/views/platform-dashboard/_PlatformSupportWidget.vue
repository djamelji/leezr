<script setup>
// ADR-746 (P2) — « Support » (platform). Données réelles (SupportTicket).
import { computed } from 'vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const open = computed(() => d.value.open ?? 0)
const empty = computed(() => (d.value.total ?? 0) === 0)
</script>

<template>
  <div class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-2 flex-shrink-0">
      <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platform.widgets.supportOverview') }}</div>
      <VAvatar :color="open > 0 ? 'warning' : 'success'" variant="tonal" size="40" rounded><VIcon icon="tabler-headset" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />
    <div v-else-if="empty" class="flex-grow-1 d-flex align-center gap-2 text-success">
      <VIcon icon="tabler-circle-check" size="22" /><span class="text-body-1 font-weight-medium">{{ t('platform.widgets.supportEmpty') }}</span>
    </div>

    <div v-else class="flex-grow-1 d-flex align-center gap-5">
      <div>
        <div class="text-h3 font-weight-medium" :class="open > 0 ? 'text-high-emphasis' : 'text-disabled'">{{ open }}</div>
        <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.ticketsOpen') }}</div>
      </div>
      <VDivider vertical class="my-2" />
      <div class="d-flex flex-column gap-2">
        <div class="d-flex align-center gap-2">
          <VIcon icon="tabler-user-question" :color="(d.unassigned ?? 0) > 0 ? 'warning' : 'disabled'" size="18" />
          <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.unassignedN', { n: d.unassigned ?? 0 }) }}</span>
        </div>
        <div class="d-flex align-center gap-2">
          <VIcon icon="tabler-checks" color="success" size="18" />
          <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.resolvedN', { n: d.resolved ?? 0 }) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
