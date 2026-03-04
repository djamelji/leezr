<script setup>
/**
 * Stable flex-column host for the dashboard grid area (ADR-198).
 *
 * Guarantees that the DashboardGrid always occupies a stable DOM position
 * regardless of variable-height content above or below it.
 * Used by both Platform and Company surfaces.
 *
 * Slots:
 *   toolbar — fixed-height toolbar above the grid
 *   default — grid + empty/loading states
 */
</script>

<template>
  <div class="dashboard-host">
    <div class="dashboard-host__toolbar">
      <slot name="toolbar" />
    </div>
    <div class="dashboard-host__grid">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.dashboard-host {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.dashboard-host__toolbar {
  flex: 0 0 auto;
}

.dashboard-host__grid {
  flex: 1 1 auto;
  min-height: 200px;
  position: relative;
}
</style>
