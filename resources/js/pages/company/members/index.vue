<script setup>
// ADR-802 — l'ancienne liste des membres est absorbée par Équipe (`/company/people`) : la liste unifiée,
// le quota et les profils incomplets (`people/_MemberHealth.vue`), le réglage des champs du profil
// (`people/_ProfileFieldsSettings.vue`), les actions par membre dans la fiche personne (ADR-768).
// Route conservée pour les liens existants : MÊME garde qu'avant, puis redirection.
definePage({ meta: { module: 'core.members', surface: 'structure', permission: 'members.view' } })

const router = useRouter()

onMounted(() => router.replace({ path: '/company/people' }))
</script>

<template>
  <div />
</template>
