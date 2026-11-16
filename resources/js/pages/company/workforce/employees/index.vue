<script setup>
// ADR-802 — l'ancienne liste RH est devenue la vue « Gestion RH » d'Équipe (`people/_PeopleHrTools.vue`).
// Cette route reste valide (liens, favoris) : MÊME garde qu'avant (module workforce + workforce.view, appliquée
// par le routeur AVANT la redirection) puis redirection en conservant les filtres d'URL.
definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const route = useRoute()
const router = useRouter()
const KEPT = ['q', 'status', 'dept', 'role', 'manager', 'contract']

onMounted(() => {
  const query = { tab: 'hr' }
  for (const k of KEPT) if (route.query[k] != null && route.query[k] !== '') query[k] = route.query[k]
  router.replace({ path: '/company/people', query })
})
</script>

<template>
  <div />
</template>
