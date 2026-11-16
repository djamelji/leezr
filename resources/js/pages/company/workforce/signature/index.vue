<script setup>
// ADR-802 — Signatures vivent dans l'espace Documents (`/company/documents/signatures`, composant
// `_SignaturesView.vue`). Ancienne route conservée : MÊME garde qu'avant, puis redirection (filtre `status` gardé).
definePage({ meta: { surface: 'hr', module: 'workforce_signature', permission: 'workforce_signature.view' } })

const route = useRoute()
const router = useRouter()

onMounted(() => router.replace({ path: '/company/documents/signatures', query: route.query.status ? { status: route.query.status } : {} }))
</script>

<template>
  <div />
</template>
