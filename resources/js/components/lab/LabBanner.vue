<script setup>
// Leezr 5.0 DESIGN LAB — mandatory banner on every prototype: fictitious data, not connected.
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

<style>
/* Leezr 5.0 lab — utility missing from Vuetify (flex children must be allowed to shrink for text-truncate).
   To move to resources/styles/styles.scss in lot L0. */
.min-w-0 { min-inline-size: 0; }
</style>
