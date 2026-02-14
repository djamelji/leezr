<script setup>
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NavbarShortcuts from '@/layouts/components/NavbarShortcuts.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import NavBarI18n from '@core/components/I18n.vue'
import { themeConfig } from '@themeConfig'
</script>

<template>
  <NavBarI18n
    v-if="themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length"
    :languages="themeConfig.app.i18n.langConfig"
  />
  <NavbarThemeSwitcher />
  <NavbarShortcuts />
  <NavBarNotifications class="me-1" />
</template>
