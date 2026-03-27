<script setup>
// ADR-376: Plan merged into Billing tabs — redirect for backward compat
definePage({ meta: { surface: 'structure', module: 'core.billing', permission: 'billing.manage' } })

const router = useRouter()

onMounted(() => {
  router.replace({ name: 'company-billing-tab', params: { tab: 'plan' } })
})
</script>

<template>
  <div />
</template>
