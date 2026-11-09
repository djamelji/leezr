<script setup>
import { computed } from 'vue'
const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const d = computed(() => props.data || {})
</script>
<template>
  <div class="widget-root d-flex flex-column h-100">
    <div class="d-flex align-center gap-2 mb-2 flex-shrink-0">
      <VAvatar color="primary" variant="tonal" size="34" rounded><VIcon icon="tabler-users" size="20" /></VAvatar>
      <span class="text-medium-emphasis text-body-2 text-truncate">Effectifs actifs</span>
    </div>
    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" text="Erreur de chargement" />
    <template v-else>
      <div class="text-h4 text-high-emphasis font-weight-medium">{{ d.active ?? 0 }}</div>
      <div class="text-caption text-medium-emphasis">{{ d.on_leave ?? 0 }} en congé · {{ d.total ?? 0 }} au total</div>
    </template>
  </div>
</template>
