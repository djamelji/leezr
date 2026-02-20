<script setup>
import { usePlatformNav } from '@/composables/usePlatformNav'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavSearchBar from '@/layouts/components/NavSearchBar.vue'
import NavbarGlobalWidgets from '@/layouts/components/NavbarGlobalWidgets.vue'
import PlatformUserProfile from '@/layouts/components/PlatformUserProfile.vue'

// @layouts plugin
import { HorizontalNavLayout } from '@layouts'

const { navItems: rawNavItems } = usePlatformNav()

const navItems = computed(() => rawNavItems.value.filter(item => !item.heading))
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
    <TheCustomizer />
  </HorizontalNavLayout>
</template>
