<script setup>
import { usePlatformNav } from '@/composables/usePlatformNav'
import { useNavStore } from '@/core/stores/nav'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavSearchBar from '@/layouts/components/NavSearchBar.vue'
import NavbarGlobalWidgets from '@/layouts/components/NavbarGlobalWidgets.vue'
import PlatformUserProfile from '@/layouts/components/PlatformUserProfile.vue'

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

const { navItems } = usePlatformNav()
const navStore = useNavStore()

// ADR-153: Gate sidebar items until nav is hydrated (defense-in-depth)
const effectiveNavItems = computed(() => navStore.platformLoaded ? navItems.value : [])
</script>

<template>
  <VerticalNavLayout :nav-items="effectiveNavItems">
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

// ADR-598 LOT D: clearer visual separation between the 6 canonical nav groups.
// Uses currentColor/opacity so it adapts to light & dark themes (no new design system).
.layout-vertical-nav {
  .nav-section-title {
    margin-block-start: 0.75rem;

    .title-text {
      font-size: 0.6875rem;
      font-weight: 600;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      opacity: 0.6;
    }
  }

  // First section title needs no extra top gap.
  .nav-items > .nav-section-title:first-child {
    margin-block-start: 0;
  }
}
</style>
