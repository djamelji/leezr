<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useNavStore } from '@/core/stores/nav'
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NavbarShortcuts from '@/layouts/components/NavbarShortcuts.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import NavBarI18n from '@core/components/I18n.vue'
import { themeConfig } from '@themeConfig'

const route = useRoute()
const navStore = useNavStore()

// ADR-161: Component registry — maps backend component key → Vue component
const widgetComponents = {
  NavbarThemeSwitcher,
}

// ADR-161: Dynamic widgets from module capabilities (filtered by backend)
const activeWidgets = computed(() => {
  const widgets = route.meta?.platform
    ? navStore.platformWidgets
    : navStore.companyWidgets

  return (widgets || [])
    .filter(w => widgetComponents[w.component])
    .sort((a, b) => a.sortOrder - b.sortOrder)
})
</script>

<template>
  <NavBarI18n
    v-if="themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length"
    :languages="themeConfig.app.i18n.langConfig"
  />
  <component
    v-for="widget in activeWidgets"
    :key="widget.key"
    :is="widgetComponents[widget.component]"
  />
  <NavbarShortcuts />
  <NavBarNotifications class="me-1" />
</template>
