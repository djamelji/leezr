<script setup>
// Leezr Premium — bandeau KPI responsive (Phase 0, ADR-741).
// Grille CSS auto-fit : s'adapte de 1 (mobile) à N colonnes sans vnode-hacking.
import { computed } from 'vue'

const props = defineProps({
  min: { type: Number, default: 220 }, // largeur mini d'une tuile avant de replier
})
const gridStyle = computed(() => ({
  display: 'grid',
  gridTemplateColumns: `repeat(auto-fit, minmax(${props.min}px, 1fr))`,
  gap: '1rem',
}))
</script>

<template>
  <div class="lzr-strip" :style="gridStyle">
    <slot />
  </div>
</template>
