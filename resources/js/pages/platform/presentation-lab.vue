<script setup>
// ADR-748 — harness de recette VISUELLE du rendu adaptatif (DEV). Rend le widget
// commercial aux tailles de la matrice. `?only=WxH` isole une taille (1 seul chart =
// conditions du vrai dashboard, sans contention ApexCharts de la grille de démo).
import { useRoute } from 'vue-router'
import PlatformCommercial from '@/views/platform-dashboard/_PlatformCommercialWidget.vue'
import { resolvePresentationMode } from '@/core/surface-engine/presentationMode'

definePage({ meta: { layout: 'platform', platform: true, module: 'platform.dashboard' } })

const route = useRoute()
const matrix = [
  { w: 1200, h: 600 }, { w: 900, h: 450 }, { w: 700, h: 500 }, { w: 500, h: 350 },
  { w: 350, h: 450 }, { w: 350, h: 180 }, { w: 1000, h: 220 },
]
const only = computed(() => {
  const q = route.query.only
  if (!q) return null
  const [w, h] = String(q).split('x').map(Number)
  return (w && h) ? { w, h } : null
})
const shown = computed(() => only.value ? [only.value] : matrix)
const live = ref({ w: 760, h: 420 })
</script>

<template>
  <div class="pa-6">
    <template v-if="!only">
      <h4 class="text-h4 mb-1">Presentation Engine — recette adaptative</h4>
      <p class="text-body-2 text-medium-emphasis mb-6">Widget <code>platform.commercial_overview</code> rendu à chaque taille. Mode via <code>resolvePresentationMode(largeur, hauteur)</code>.</p>

      <div class="mb-8">
        <div class="d-flex align-center gap-4 mb-2 flex-wrap">
          <span class="text-overline">Resize dynamique</span>
          <div style="inline-size: 220px"><VSlider v-model="live.w" :min="280" :max="1200" :step="10" hide-details label="L" thumb-label density="compact" /></div>
          <div style="inline-size: 220px"><VSlider v-model="live.h" :min="160" :max="640" :step="10" hide-details label="H" thumb-label density="compact" /></div>
          <VChip label color="primary" variant="tonal">{{ live.w }}×{{ live.h }} → {{ resolvePresentationMode(live.w, live.h) }}</VChip>
        </div>
        <div class="lab-box" :style="{ inlineSize: live.w + 'px', blockSize: live.h + 'px' }"><PlatformCommercial /></div>
      </div>
    </template>

    <div class="lab-grid">
      <div v-for="m in shown" :key="`${m.w}x${m.h}`">
        <div class="d-flex align-center gap-2 mb-1">
          <VChip size="small" label variant="outlined">{{ m.w }}×{{ m.h }}</VChip>
          <VChip size="small" label color="primary" variant="tonal">{{ resolvePresentationMode(m.w, m.h) }}</VChip>
        </div>
        <div class="lab-box" :data-size="`${m.w}x${m.h}`" :style="{ inlineSize: m.w + 'px', blockSize: m.h + 'px' }"><PlatformCommercial /></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.lab-box { background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-theme-on-surface), 0.1); border-radius: 12px; overflow: hidden; max-inline-size: 100%; }
.lab-grid { display: flex; flex-direction: column; gap: 28px; }
</style>
