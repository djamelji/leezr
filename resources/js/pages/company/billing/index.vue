<script setup>
// Robustesse navigation : /company/billing (nu) ou tout lien legacy (ex. ?tab=…) redirige vers
// l'onglet overview canonique, au lieu d'une 404. La route canonique reste /company/billing/:tab.
definePage({ meta: { surface: 'structure', module: 'core.billing', permission: 'billing.manage' } })

const router = useRouter()
const route = useRoute()

onMounted(() => {
  // Si un ancien lien passe ?tab=xxx, on honore l'onglet demandé ; sinon overview.
  const legacyTab = typeof route.query.tab === 'string' ? route.query.tab : 'overview'
  router.replace({ name: 'company-billing-tab', params: { tab: legacyTab } })
})
</script>

<template>
  <div />
</template>
