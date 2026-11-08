<script setup>
// Leezr Premium — état vide standardisé (Phase 0, ADR-741).
defineProps({
  icon: { type: String, default: 'tabler-inbox' },
  color: { type: String, default: 'secondary' },
  title: { type: String, default: 'Aucune donnée' },
  text: { type: String, default: '' },
  actionText: { type: String, default: '' },
  to: { type: [String, Object], default: null },
})
const emit = defineEmits(['action'])
</script>

<template>
  <div class="d-flex flex-column align-center justify-center text-center py-8 px-4">
    <VAvatar :color="color" variant="tonal" rounded size="72" class="mb-4">
      <VIcon :icon="icon" size="36" />
    </VAvatar>
    <div class="text-h6 mb-1">{{ title }}</div>
    <div v-if="text" class="text-body-2 text-medium-emphasis mb-4" style="max-inline-size: 360px;">{{ text }}</div>
    <VBtn v-if="actionText" :to="to" variant="flat" class="lzr-cta" @click="emit('action')">
      {{ actionText }}
    </VBtn>
  </div>
</template>
