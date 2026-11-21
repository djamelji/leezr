<script setup>
import AppAccessState from '@/components/app/AppAccessState.vue'

/**
 * ADR-839 (Leezr 4.2 M3, audit UXP-04) — a forbidden Platform page no longer bounces silently to another page :
 * the operator reads why (profile without this access, or the area is switched off), can go back or to the
 * first page their profile allows. No data, no permission key as the explanation.
 */

definePage({
  meta: {
    layout: 'platform',
    platform: true,
  },
})

const { t } = useI18n()
const route = useRoute()
const moduleOff = computed(() => route.query.reason === 'module')
</script>

<template>
  <AppAccessState
    :icon="moduleOff ? 'tabler-plug-off' : 'tabler-lock'"
    :title="moduleOff ? t('platformForbidden.moduleTitle') : t('platformForbidden.title')"
    :message="moduleOff ? t('platformForbidden.moduleMessage') : t('platformForbidden.message')"
    :hint="t('platformForbidden.hint')"
    :actions="[{ label: t('platformForbidden.home'), to: '/platform', testid: 'access-state-home' }]"
    testid="platform-forbidden"
  />
</template>
