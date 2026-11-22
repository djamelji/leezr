<script setup>
// ADR-820 — espace Équipe, vue « Personnes ». Les anciens liens `?tab=hr|roles` (ADR-766/802) sont redirigés
// vers /company/people/hr et /company/people/roles (onglet dans le chemin, ADR-475), filtres conservés.
import PeopleSpace from './_PeopleSpace.vue'

definePage({ meta: { module: 'core.members', anyPermission: ['members.view', 'workforce.view', 'roles.view'] } })

const route = useRoute()
const router = useRouter()
const legacy = { roles: 'company-people-roles' }[route.query.tab] // ADR-864 : ?tab=hr = Personnes itself
if (legacy) {
  const { tab: _, ...query } = route.query

  router.replace({ name: legacy, query })
}
</script>

<template>
  <PeopleSpace
    v-if="!legacy"
    section="people"
  />
</template>
