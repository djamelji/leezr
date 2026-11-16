<script setup>
// ADR-802 — les modèles de contrat vivent dans l'espace Documents (`/company/documents/templates`, composant
// `_ContractTemplatesView.vue`). Ancienne route conservée : MÊME garde qu'avant, puis redirection.
definePage({ meta: { module: 'workforce_contract', permission: 'workforce_contract.view' } })

const router = useRouter()

onMounted(() => router.replace({ path: '/company/documents/templates' }))
</script>

<template>
  <div />
</template>
