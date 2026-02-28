<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NavbarShortcuts from '@/layouts/components/NavbarShortcuts.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import NavBarI18n from '@core/components/I18n.vue'
import { themeConfig } from '@themeConfig'
import { useModuleStore } from '@/core/stores/module'
import { useAuthStore } from '@/core/stores/auth'

const route = useRoute()
const moduleStore = useModuleStore()
const authStore = useAuthStore()

// ADR-159: Theme toggle visibility
// Platform surface: always visible (platform admins always have theme control)
// Company surface: visible only if core.theme module is active AND user has theme.view
const showThemeToggle = computed(() => {
  if (route.meta?.platform) return true

  return moduleStore.isActive('core.theme') && authStore.hasPermission('theme.view')
})
</script>

<template>
  <NavBarI18n
    v-if="themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length"
    :languages="themeConfig.app.i18n.langConfig"
  />
  <NavbarThemeSwitcher v-if="showThemeToggle" />
  <NavbarShortcuts />
  <NavBarNotifications class="me-1" />
</template>
