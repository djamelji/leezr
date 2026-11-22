<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { usePlatformNav } from '@/composables/usePlatformNav'
import { useNavStore } from '@/core/stores/nav'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavSearchBar from '@/layouts/components/NavSearchBar.vue'
import NavbarGlobalWidgets from '@/layouts/components/NavbarGlobalWidgets.vue'
import PlatformUserProfile from '@/layouts/components/PlatformUserProfile.vue'

// @layouts plugin
import { HorizontalNavLayout } from '@layouts'

const { navItems: rawNavItems } = usePlatformNav()
const navStore = useNavStore()

const navItems = computed(() => {
  if (!navStore.platformLoaded) return [] // ADR-153: gate until hydrated
  return rawNavItems.value.filter(item => !item.heading)
})

// ADR-842 (M5, UXP-12) — the floating theme customizer is a template tool : reserved to manage_theme_settings
// ADR-860 (L7, 05 §4) — the floating customizer gear overlapped titles and tabs on every Platform page : it now lives
// where theme settings are edited (Réglages › Thème), for the same permission. Nothing else changes.
const customizerRoute = useRoute()
const canCustomizeTheme = computed(() => usePlatformAuthStore().hasPermission('manage_theme_settings')
  && customizerRoute.name === 'platform-settings-tab' && ['theme', 'typography'].includes(String(customizerRoute.params.tab)))
</script>

<template>
  <HorizontalNavLayout :nav-items="navItems">
    <!-- 👉 navbar -->
    <template #navbar>
      <RouterLink
        to="/platform"
        class="app-logo d-flex align-center gap-x-3 text-decoration-none"
      >
        <BrandLogo size="md" />
      </RouterLink>

      <VSpacer />

      <NavSearchBar />
      <NavbarGlobalWidgets />
      <PlatformUserProfile />
    </template>

    <!-- 👉 Pages -->
    <slot />

    <!-- 👉 Footer -->
    <template #footer>
      <Footer />
    </template>

    <!-- 👉 Customizer -->
    <!-- ADR-842 (M5, UXP-12) : the template's theme customizer only for operators who manage the theme -->
    <TheCustomizer v-if="canCustomizeTheme" />
  </HorizontalNavLayout>
</template>
