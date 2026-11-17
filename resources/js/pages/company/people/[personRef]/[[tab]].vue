<script setup>
// ADR-820 — fiche d'une personne ; la rubrique est dans le chemin (/company/people/{ref}/{rubrique}).
import PersonFile from '../_PersonFile.vue'

definePage({ name: 'company-people-person-ref', meta: { module: 'core.members', anyPermission: ['members.view', 'workforce.view'] } }) // nom historique conservé
</script>

<template>
  <PersonFile />
</template>
