<script setup>
// ADR-782 (UX6) — surface neutre (bordure fine, coins 10 px) qui regroupe des sections ou des lignes.
// À préférer à une VCard quand il n'y a ni titre de carte ni action de carte.
</script>

<template>
  <div class="app-surface">
    <slot />
  </div>
</template>

<style scoped>
.app-surface { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 10px; background: rgb(var(--v-theme-surface)); overflow: hidden; }
</style>
