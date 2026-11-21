<script setup>
import AppAccessState from '@/components/app/AppAccessState.vue'
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyNav } from '@/composables/useCompanyNav'

/**
 * ADR-839 (Leezr 4.2 M3) — permission missing (situation A) : explicit, no data, no automatic redirect
 * (the former 5-second countdown sent the user elsewhere before reading). Back + the user's own home.
 */

definePage({})

const { t } = useI18n()
const auth = useAuthStore()
const { firstAccessibleRoute } = useCompanyNav()

const home = computed(() =>
  firstAccessibleRoute.value !== '/'
    ? firstAccessibleRoute.value
    : auth.workspace === 'home' ? '/home' : '/dashboard',
)
</script>

<template>
  <AppAccessState
    :title="t('forbidden.title')"
    :message="t('forbidden.message')"
    :hint="t('forbidden.hint')"
    :actions="[{ label: t('forbidden.goToDashboard'), to: home, testid: 'access-state-home' }]"
    testid="company-forbidden"
  />
</template>
