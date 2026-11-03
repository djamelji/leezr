<script setup>
// PRODUCT-UX-3 / P3-B (ADR-687) — single canonical Company home.
// The former Workforce action home is consolidated INTO /dashboard (the widget-engine
// home), where "À traiter" priorities now live as a fixed top section. This route
// redirects to the canonical home to avoid two competing dashboards (deep-link compat).
definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const router = useRouter()
router.replace('/dashboard')
</script>

<template>
  <div class="d-flex justify-center py-10">
    <VProgressCircular indeterminate color="primary" />
  </div>
</template>
