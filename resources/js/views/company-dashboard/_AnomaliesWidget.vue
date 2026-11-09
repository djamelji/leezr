<script setup>
import { computed } from 'vue'
const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const d = computed(() => props.data || {})
const color = computed(() => (d.value.anomalies ?? 0) > 0 ? 'error' : 'success')
</script>
<template>
  <div class="widget-root d-flex flex-column h-100">
    <div class="d-flex align-center gap-2 mb-2 flex-shrink-0">
      <VAvatar :color="color" variant="tonal" size="34" rounded><VIcon icon="tabler-alert-triangle" size="20" /></VAvatar>
      <span class="text-medium-emphasis text-body-2 text-truncate">Anomalies de pointage</span>
    </div>
    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" text="Erreur de chargement" />
    <template v-else>
      <div class="text-h4 text-high-emphasis font-weight-medium">{{ d.anomalies ?? 0 }}</div>
      <div class="text-caption text-medium-emphasis">{{ d.incomplete ?? 0 }} incomplet(s) · {{ d.not_clocked ?? 0 }} non pointé(s)</div>
    </template>
  </div>
</template>
