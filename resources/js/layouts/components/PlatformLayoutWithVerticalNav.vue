<script setup>
import platformNavItems from '@/navigation/platform'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavSearchBar from '@/layouts/components/NavSearchBar.vue'
import NavbarGlobalWidgets from '@/layouts/components/NavbarGlobalWidgets.vue'
import PlatformUserProfile from '@/layouts/components/PlatformUserProfile.vue'

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

const platformAuth = usePlatformAuthStore()

const navItems = computed(() => {
  return platformNavItems.filter(item => {
    // Keep headings only if at least one sibling item is visible
    if (item.heading) return true

    // Items without permission are always visible (e.g. Dashboard)
    if (!item.permission) return true

    return platformAuth.hasPermission(item.permission)
  }).filter((item, index, arr) => {
    // Remove trailing headings with no visible items after them
    if (item.heading) {
      const next = arr[index + 1]

      return next && !next.heading
    }

    return true
  })
})
</script>

<template>
  <VerticalNavLayout :nav-items="navItems">
    <!-- 👉 navbar -->
    <template #navbar="{ toggleVerticalOverlayNavActive }">
      <div class="d-flex h-100 align-center">
        <IconBtn
          id="vertical-nav-toggle-btn"
          class="ms-n3 d-lg-none"
          @click="toggleVerticalOverlayNavActive(true)"
        >
          <VIcon
            size="26"
            icon="tabler-menu-2"
          />
        </IconBtn>

        <NavSearchBar class="ms-lg-n3" />

        <VSpacer />

        <NavbarGlobalWidgets />
        <PlatformUserProfile />
      </div>
    </template>

    <!-- 👉 Pages -->
    <slot />

    <!-- 👉 Footer -->
    <template #footer>
      <Footer />
    </template>

    <!-- 👉 Customizer -->
    <TheCustomizer />
  </VerticalNavLayout>
</template>
