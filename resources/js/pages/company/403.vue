<script setup>
import AppAccessState from '@/components/app/AppAccessState.vue'
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyNav } from '@/composables/useCompanyNav'
import { useRuntimeStore } from '@/core/runtime/runtime'
import { cacheRemove } from '@/core/runtime/cache'

/**
 * ADR-839 (Leezr 4.2 M3) — permission missing (situation A) : explicit, no data, no automatic redirect
 * (the former 5-second countdown sent the user elsewhere before reading). Back + the user's own home.
 */

definePage({})

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

// ADR-840 (M3.1) — the right exists on the role but waits for an administrator's confirmation (ADR-808) :
// a distinct explanation, the access itself stays refused until confirmed.
const pending = computed(() => route.query.reason === 'pending')
const router = useRouter()

// The rights come from a 5-minute client cache : on a « pending » page, re-read them once (same invalidation path
// as the realtime events). If a pending right has been confirmed meanwhile, reopen the requested page — the router
// checks it again ; nothing is granted here.
onMounted(async () => {
  if (!pending.value || !route.query.from) return
  const before = auth.pendingPermissions.length
  try {
    useRuntimeStore()._handleRealtimeInvalidation(['features:nav']) // menu refreshed in the background
    cacheRemove('auth:companies')
    await auth.fetchMyCompanies()
  }
  catch { return }
  if (auth.pendingPermissions.length < before) router.replace(String(route.query.from))
})

const { firstAccessibleRoute } = useCompanyNav()

const home = computed(() =>
  firstAccessibleRoute.value !== '/'
    ? firstAccessibleRoute.value
    : auth.workspace === 'home' ? '/home' : '/dashboard',
)
</script>

<template>
  <AppAccessState
    v-if="pending"
    icon="tabler-hourglass"
    color="info"
    :title="t('forbidden.pendingTitle')"
    :message="t('forbidden.pendingMessage')"
    :hint="t('forbidden.pendingHint')"
    :actions="[{ label: t('forbidden.goToDashboard'), to: home, testid: 'access-state-home' }]"
    testid="company-access-pending"
  />
  <AppAccessState
    v-else
    :title="t('forbidden.title')"
    :message="t('forbidden.message')"
    :hint="t('forbidden.hint')"
    :actions="[{ label: t('forbidden.goToDashboard'), to: home, testid: 'access-state-home' }]"
    testid="company-forbidden"
  />
</template>
