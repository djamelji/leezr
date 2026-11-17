<script setup>
// ADR-820 — /platform/jobdomains/{id} ouvre la section « Général ». Les anciens liens `?tab=` (ADR-797/799)
// sont redirigés vers la section qui porte désormais leur contenu (ADR-475 : onglet dans le chemin).
definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.jobdomains',
    permission: 'manage_jobdomains',
    navActiveLink: 'platform-catalog-tab',
  },
})

const route = useRoute()
const router = useRouter()
const LEGACY = { general: 'general', modules: 'modules', roles: 'presets', organization: 'presets', requirements: 'presets', planning: 'presets' }

router.replace({ name: 'platform-jobdomains-id-tab', params: { id: route.params.id, tab: LEGACY[route.query.tab] || 'general' } })
</script>

<template>
  <div />
</template>
