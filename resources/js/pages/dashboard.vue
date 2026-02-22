<script setup>
import { useAuthStore } from '@/core/stores/auth'
import DashboardManagement from '@/pages/company/dashboard/_DashboardManagement.vue'
import DashboardOperational from '@/pages/company/dashboard/_DashboardOperational.vue'

const auth = useAuthStore()

const dashboardComponent = computed(() =>
  auth.roleLevel === 'management' ? DashboardManagement : DashboardOperational,
)
</script>

<template>
  <component :is="dashboardComponent" />
</template>
