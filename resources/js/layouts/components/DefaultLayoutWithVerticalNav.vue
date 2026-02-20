<script setup>
import { useCompanyNav } from '@/composables/useCompanyNav'
// Components
import Footer from '@/layouts/components/Footer.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

const { navItems } = useCompanyNav()
</script>

<template>
  <VerticalNavLayout :nav-items="navItems">
    <!-- navbar -->
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

        <VSpacer />

        <UserProfile />
      </div>
    </template>

    <!-- Pages -->
    <slot />

    <!-- Footer -->
    <template #footer>
      <Footer />
    </template>

  </VerticalNavLayout>
</template>

<style lang="scss">
// Center BrandLogo in vertical nav header
.layout-vertical-nav .nav-header .app-title-wrapper {
  flex: 1;
  display: flex;
  justify-content: center;
  margin-inline-end: 0;
}

// Smooth size transition for collapsed state
.layout-vertical-nav .brand-logo {
  transition: font-size 0.25s ease-in-out;
}

// Collapsed state: shrink font to fit 80px width
.layout-vertical-nav-collapsed .layout-vertical-nav:not(.hovered) {
  .brand-logo {
    font-size: 14px !important;
  }
}
</style>
