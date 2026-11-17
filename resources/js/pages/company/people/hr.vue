<script setup>
// ADR-820 — vue « hr » de l'espace Équipe : onglet dans le chemin (ADR-475).
import PeopleSpace from './_PeopleSpace.vue'

definePage({ meta: { module: 'core.members', anyPermission: ['members.view', 'workforce.view', 'roles.view'] } })
</script>

<template>
  <PeopleSpace section="hr" />
</template>
