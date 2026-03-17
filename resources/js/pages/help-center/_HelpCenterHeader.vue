<script setup>
import { useReturnNavigation } from '@/composables/useReturnNavigation'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

const { activeSpaces, isAuthenticated } = useReturnNavigation()
</script>

<template>
  <VAppBar
    color="surface"
    class="px-4"
    flat
  >
    <div class="d-flex align-center">
      <RouterLink
        to="/help-center"
        class="d-flex gap-x-4 align-center text-decoration-none"
      >
        <div class="app-logo">
          <VNodeRenderer :nodes="themeConfig.app.logo" />
          <h1 class="app-logo-title">
            {{ themeConfig.app.title }}
          </h1>
        </div>
      </RouterLink>
    </div>

    <VSpacer />

    <div class="d-flex gap-x-2">
      <VBtn
        v-for="space in activeSpaces"
        :key="space.key"
        variant="tonal"
        color="primary"
        :href="space.url"
      >
        <VIcon
          :icon="space.icon"
          size="18"
          class="me-1"
        />
        {{ $t(space.labelKey) }}
      </VBtn>

      <VBtn
        v-if="!isAuthenticated"
        variant="tonal"
        color="primary"
        :to="{ name: 'login' }"
      >
        {{ $t('documentation.login') }}
      </VBtn>
    </div>
  </VAppBar>
</template>
