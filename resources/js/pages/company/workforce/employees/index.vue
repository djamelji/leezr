<script setup>
// ADR-802 / ADR-864 — l'ancienne liste RH vit désormais dans Personnes (outils RH, mêmes droits).
// Cette route reste valide (liens, favoris) : MÊME garde qu'avant (module workforce + workforce.view, appliquée
// par le routeur AVANT la redirection) puis redirection en conservant les filtres d'URL.
definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const route = useRoute()
const router = useRouter()
const KEPT = ['q', 'status', 'dept', 'role', 'manager', 'contract']

onMounted(() => {
  const query = {}
  for (const k of KEPT) if (route.query[k] != null && route.query[k] !== '') query[k] = route.query[k]
  router.replace({ name: 'company-people', query }) // ADR-864 : une seule interface Personnes
})
</script>

<template>
  <div />
</template>
