<script setup>
import { computed } from 'vue'
const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const d = computed(() => props.data || {})
</script>
<template>
  <div class="widget-root d-flex flex-column h-100">
    <div class="d-flex align-center gap-2 mb-2 flex-shrink-0">
      <VAvatar color="warning" variant="tonal" size="34" rounded><VIcon icon="tabler-clipboard-check" size="20" /></VAvatar>
      <span class="text-medium-emphasis text-body-2 text-truncate">Feuilles à valider</span>
    </div>
    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" text="Erreur de chargement" />
    <template v-else>
      <div class="text-h4 text-high-emphasis font-weight-medium">{{ d.pending ?? 0 }}</div>
      <VBtn v-if="(d.pending ?? 0) > 0" :to="{ name: 'company-workforce-time', query: { tab: 'timesheets' } }" variant="flat" size="small" class="mt-1 align-self-start lzr-cta">Valider</VBtn>
      <div v-else class="text-caption text-medium-emphasis">À jour</div>
    </template>
  </div>
</template>
