<script setup>
import { useAuthStore } from '@/core/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div>
    <VCard class="mb-6">
      <VCardTitle>
        Welcome, {{ auth.user?.display_name }}
      </VCardTitle>
      <VCardText>
        <p v-if="auth.currentCompany">
          You are working in <strong>{{ auth.currentCompany.name }}</strong>.
        </p>
        <p v-else>
          Loading your company...
        </p>
      </VCardText>
    </VCard>

    <VCard>
      <VCardTitle>My Tasks</VCardTitle>
      <VCardText>
        <p class="text-body-1 text-disabled">
          Your assigned tasks and deliveries will appear here.
        </p>
      </VCardText>
    </VCard>
  </div>
</template>
