<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { usePlatformNav } from '@/composables/usePlatformNav'

// ADR-801 (P0-2) — `/platform` is a pure DISPATCHER (no permission of its own): it is also the
// fallback of every refused Platform page. Dashboard when allowed, otherwise the first nav entry the
// user can reach (backend-filtered), otherwise the personal account (always allowed). Never assumes
// super-admin, never loops.
definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.dashboard', // route owner (PageModuleAlignmentTest) ; the guard never bounces /platform onto itself
  },
})

const router = useRouter()
const auth = usePlatformAuthStore()
const { firstAccessibleRoute } = usePlatformNav()

onMounted(() => {
  if (auth.hasPermission('view_dashboard') && !auth.isModuleInactive('platform.dashboard')) {
    router.replace({ name: 'platform-dashboard-tab', params: { tab: 'overview' } })

    return
  }

  const first = firstAccessibleRoute.value

  router.replace(first && first !== '/platform' ? first : { name: 'platform-account-tab', params: { tab: 'account' } })
})
</script>

<template>
  <div />
</template>
