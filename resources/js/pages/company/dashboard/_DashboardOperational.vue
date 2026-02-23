<script setup>
import { useAuthStore } from '@/core/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
</script>

<template>
  <div>
    <VCard class="mb-6">
      <VCardTitle>
        {{ t('dashboard.welcome', { name: auth.user?.display_name }) }}
      </VCardTitle>
      <VCardText>
        <p v-if="auth.currentCompany">
          {{ t('dashboard.workingIn', { name: auth.currentCompany.name }) }}
        </p>
        <p v-else>
          {{ t('dashboard.loadingCompany') }}
        </p>
      </VCardText>
    </VCard>

    <VCard>
      <VCardTitle>{{ t('dashboard.myTasks') }}</VCardTitle>
      <VCardText>
        <p class="text-body-1 text-disabled">
          {{ t('dashboard.tasksPlaceholder') }}
        </p>
      </VCardText>
    </VCard>
  </div>
</template>
