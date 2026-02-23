<script setup>
import { useAuthStore } from '@/core/stores/auth'
import PlanBadgeWidget from './_PlanBadgeWidget.vue'

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
          {{ t('dashboard.viewingCompany', { name: auth.currentCompany.name }) }}
        </p>
        <p v-else>
          {{ t('dashboard.loadingCompany') }}
        </p>
      </VCardText>
    </VCard>

    <PlanBadgeWidget class="mb-6" />
  </div>
</template>
