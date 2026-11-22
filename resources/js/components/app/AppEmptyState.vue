<script setup>
// Leezr 5.0 (L0, ADR-855) — empty / error state: icon, what is missing, the next action. Never a lone « 0 ».
defineProps({
  icon: { type: String, default: 'tabler-inbox' },
  color: { type: String, default: 'primary' },
  title: { type: String, required: true },
  text: { type: String, default: '' },
  action: { type: String, default: '' },
  to: { type: [String, Object], default: null },
  compact: { type: Boolean, default: false },
})
defineEmits(['action'])
</script>

<template>
  <div
    class="app-empty"
    :class="{ 'app-empty--compact': compact }"
  >
    <VAvatar
      :color="color"
      variant="tonal"
      :size="compact ? 40 : 56"
    >
      <VIcon
        :icon="icon"
        :size="compact ? 22 : 28"
      />
    </VAvatar>
    <div class="app-empty__title">
      {{ title }}
    </div>
    <div
      v-if="text"
      class="app-empty__text"
    >
      {{ text }}
    </div>
    <VBtn
      v-if="action"
      :to="to || undefined"
      size="small"
      variant="tonal"
      :color="color"
      class="mt-1"
      @click="!to && $emit('action')"
    >
      {{ action }}
    </VBtn>
  </div>
</template>

<style scoped>
.app-empty { flex: 1 1 auto; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: .375rem; padding: 1.5rem 1rem; }
.app-empty--compact { padding: .5rem; }
.app-empty__title { font-weight: 600; color: rgb(var(--v-theme-on-surface)); }
.app-empty__text { font-size: .8125rem; max-inline-size: 36ch; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
</style>
