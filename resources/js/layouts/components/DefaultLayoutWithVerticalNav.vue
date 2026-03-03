<script setup>
import { useCompanyNav } from '@/composables/useCompanyNav'
import { useNavStore } from '@/core/stores/nav'
// Components
import Footer from '@/layouts/components/Footer.vue'
import NavbarGlobalWidgets from '@/layouts/components/NavbarGlobalWidgets.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

const { navItems } = useCompanyNav()
const navStore = useNavStore()

// ADR-153: Gate sidebar items until nav is hydrated (defense-in-depth)
const effectiveNavItems = computed(() => navStore.companyLoaded ? navItems.value : [])

// ─── Overlay stuck failsafe ─────────────────────────────
// If .layout-overlay stays visible for >10s without user interaction,
// auto-dismiss it by simulating a click (resets Vue reactive refs).
// This catches edge cases where normal cleanup (afterEach, route watcher) fails.
let overlayStuckTimer = null

function startOverlayWatch() {
  clearInterval(overlayStuckTimer)
  let stuckSince = 0

  overlayStuckTimer = setInterval(() => {
    const overlay = document.querySelector('.layout-overlay.visible')
    if (overlay) {
      if (!stuckSince) {
        stuckSince = Date.now()
      }
      else if (Date.now() - stuckSince > 10_000) {
        overlay.click()
        stuckSince = 0
      }
    }
    else {
      stuckSince = 0
    }
  }, 2000)
}

onMounted(startOverlayWatch)
onUnmounted(() => clearInterval(overlayStuckTimer))
</script>

<template>
  <VerticalNavLayout :nav-items="effectiveNavItems">
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

        <NavbarGlobalWidgets />
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
