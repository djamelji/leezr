<script setup>
// ADR-745 — coquille KPI compacte et dense (strip). Remplit la cellule sans vide :
// avatar + libellé en haut, grande valeur au centre, ligne secondaire/action en bas.
// Présentationnel pur — pas de VCard (la grille en fournit une).
defineProps({
  icon: { type: String, default: 'tabler-point' },
  color: { type: String, default: 'primary' },
  label: { type: String, default: '' },
  value: { type: [String, Number], default: '' },
  caption: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
  tone: { type: String, default: '' }, // '', 'positive' → valeur verte quand état « tout va bien »
})
</script>

<template>
  <div class="kpi-shell d-flex flex-column pa-4">
    <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0">
      <span class="text-body-2 text-medium-emphasis text-truncate">{{ label }}</span>
      <VAvatar :color="color" variant="tonal" size="38" rounded>
        <VIcon :icon="icon" size="20" />
      </VAvatar>
    </div>

    <VProgressCircular v-if="loading" indeterminate size="22" width="2" color="primary" class="my-auto" />
    <VIcon v-else-if="error" icon="tabler-alert-circle" color="error" class="my-auto" />

    <template v-else>
      <div class="flex-grow-1 d-flex align-center">
        <span
          class="text-h4 font-weight-medium"
          :class="tone === 'positive' ? 'text-success' : 'text-high-emphasis'"
        >{{ value }}</span>
      </div>
      <div class="kpi-foot d-flex align-center justify-space-between gap-2 flex-shrink-0">
        <span class="text-caption text-medium-emphasis text-truncate">{{ caption }}</span>
        <slot name="action" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.kpi-shell { block-size: 100%; }
.kpi-foot { min-block-size: 24px; }
</style>
