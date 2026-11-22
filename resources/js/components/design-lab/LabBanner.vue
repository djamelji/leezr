<script setup>
// Leezr 5.0 (L0) — mandatory banner on every prototype: fictitious data, not connected.
defineProps({ name: { type: String, required: true }, compare: { type: String, default: '' } })
</script>

<template>
  <VAlert
    type="warning"
    variant="tonal"
    density="compact"
    icon="tabler-flask"
    class="mb-5"
    data-test="design-lab-banner"
  >
    <strong>Prototype Leezr 5.0 — {{ name }}.</strong>
    Données <strong>fictives</strong>, aucune action n'est connectée. Proposition visuelle soumise à validation.
    <span v-if="compare"> Écran actuel : <code>{{ compare }}</code>.</span>
  </VAlert>
</template>

