<script setup>
// Leezr 5.0 (L0, ADR-855) — semantic status chip: ONE colour per meaning everywhere (06-design-system §3).
//   success = good/done/active · info = waiting for someone else · primary = in progress on our side
//   warning = action expected · error = blocking/late · secondary = inactive/archived
const props = defineProps({
  tone: { type: String, default: 'secondary' }, // success | info | primary | warning | error | secondary
  label: { type: String, required: true },
  icon: { type: String, default: '' },
  dot: { type: Boolean, default: false },
  size: { type: String, default: 'small' },
})
const TONES = ['success', 'info', 'primary', 'warning', 'error', 'secondary']
const color = computed(() => (TONES.includes(props.tone) ? props.tone : 'secondary'))
</script>

<template>
  <VChip
    :color="color"
    :size="size"
    variant="tonal"
    label
    class="app-status"
  >
    <span
      v-if="dot"
      class="app-status__dot"
      :class="`bg-${color}`"
    />
    <VIcon
      v-else-if="icon"
      start
      size="14"
      :icon="icon"
    />{{ label }}
  </VChip>
</template>

<style scoped>
.app-status__dot { inline-size: 7px; block-size: 7px; border-radius: 50%; margin-inline-end: .375rem; display: inline-block; }
</style>
