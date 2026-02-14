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
          You are viewing <strong>{{ auth.currentCompany.name }}</strong> as <strong>{{ auth.currentCompany.role }}</strong>.
        </p>
        <p v-else>
          Loading your company...
        </p>
      </VCardText>
    </VCard>
  </div>
</template>
