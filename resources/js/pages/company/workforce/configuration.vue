<script setup>
// ADR-639 (W-B) — la configuration RH (pauses / temps de travail) est désormais intégrée au centre
// « Paramètres RH ». Cette route historique est conservée et redirige vers le hub (aucun 404, aucun
// doublon d'interface : la même donnée est éditée via les mêmes endpoints dans le hub).
definePage({
  meta: { surface: 'structure', module: 'workforce', navActiveLink: 'company-workforce-settings-tab', permission: 'workforce.view' },
})

const router = useRouter()

onMounted(() => {
  router.replace('/company/workforce/settings/worktime')
})
</script>

<template>
  <div class="d-flex justify-center py-12">
    <VProgressCircular indeterminate />
  </div>
</template>
