<script setup>
import { useAuthStore } from '@/core/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()

const actions = computed(() => {
  const items = [
    {
      key: 'profile',
      icon: 'tabler-building',
      label: t('dashboard.quickActions.profile'),
      to: '/company/profile/overview',
      color: 'primary',
    },
    {
      key: 'members',
      icon: 'tabler-users-plus',
      label: t('dashboard.quickActions.members'),
      to: '/company/members',
      color: 'info',
    },
    {
      key: 'documents',
      icon: 'tabler-file-check',
      label: t('dashboard.quickActions.documents'),
      to: { name: 'company-documentation-tab', params: { tab: 'overview' } },
      color: 'success',
    },
    {
      key: 'billing',
      icon: 'tabler-wallet',
      label: t('dashboard.quickActions.billing'),
      to: { name: 'company-billing-tab', params: { tab: 'overview' } },
      color: 'warning',
    },
  ]

  // Only show billing for owners/admins
  if (!auth.isAdministrative)
    return items.filter(i => i.key !== 'billing')

  return items
})
</script>

<template>
  <VCard class="mb-6">
    <VCardText>
      <div class="d-flex align-center gap-2 mb-3">
        <VIcon
          icon="tabler-rocket"
          size="20"
          color="primary"
        />
        <span class="text-body-1 font-weight-medium">{{ t('dashboard.quickActions.title') }}</span>
      </div>
      <div class="d-flex flex-wrap gap-3">
        <VBtn
          v-for="action in actions"
          :key="action.key"
          :to="action.to"
          :color="action.color"
          variant="tonal"
          size="small"
        >
          <VIcon
            start
            :icon="action.icon"
            size="18"
          />
          {{ action.label }}
        </VBtn>
      </div>
    </VCardText>
  </VCard>
</template>
