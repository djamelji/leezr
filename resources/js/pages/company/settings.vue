<script setup>
definePage({ meta: { module: 'core.settings', surface: 'structure' } })

const router = useRouter()

onMounted(() => {
  router.replace({ name: 'company-profile-tab', params: { tab: 'overview' } })
})
</script>

<template>
  <div />
</template>
