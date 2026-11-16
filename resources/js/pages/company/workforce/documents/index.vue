<script setup>
// ADR-802 — le registre RH vit dans l'espace Documents (`/company/documents/people`, composant
// `_HrDocumentsView.vue`). Ancienne route conservée : MÊME garde qu'avant, puis redirection
// (`?tab=templates|signatures` ouvre la vue correspondante).
definePage({ meta: { module: 'workforce_documents', permission: 'workforce_documents.view' } })

const route = useRoute()
const router = useRouter()

onMounted(() => {
  const view = ['templates', 'signatures'].includes(route.query.tab) ? route.query.tab : 'people'

  router.replace({ path: `/company/documents/${view}` })
})
</script>

<template>
  <div />
</template>
