<script setup>
definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.dashboard',
  },
})

const router = useRouter()

// Redirect to dashboard hub
onMounted(() => {
  router.replace({ name: 'platform-dashboard-tab', params: { tab: 'overview' } })
})
</script>

<template>
  <div />
</template>
