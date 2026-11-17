<script setup>
// ADR-792 (L2) — « Informations & documents demandés » (tous les postes, ou un poste choisi).
// ADR-817 (L1) : l'éditeur est partagé avec la fiche d'un poste (Organisation › Postes › [poste]).
import RequirementsEditor from './_RequirementsEditor.vue'

definePage({ meta: { module: 'workforce', surface: 'structure', anyPermission: ['settings.manage', 'documents.configure'] } })
</script>

<template>
  <RequirementsEditor />
</template>
