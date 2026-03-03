<script setup>
import { useCompanyNav } from '@/composables/useCompanyNav'
import { useNavStore } from '@/core/stores/nav'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavbarGlobalWidgets from '@/layouts/components/NavbarGlobalWidgets.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'
import { HorizontalNavLayout } from '@layouts'

const { navItems } = useCompanyNav()
const navStore = useNavStore()

// ADR-153: Gate nav items until hydrated (defense-in-depth)
const effectiveNavItems = computed(() => navStore.companyLoaded ? navItems.value : [])
</script>

<template>
  <HorizontalNavLayout :nav-items="effectiveNavItems">
    <!-- 👉 navbar -->
    <template #navbar>
      <RouterLink
        to="/"
        class="app-logo d-flex align-center gap-x-3 text-decoration-none"
      >
        <BrandLogo size="md" />
      </RouterLink>
      <VSpacer />

      <NavbarGlobalWidgets />
      <UserProfile />
    </template>

    <!-- 👉 Pages -->
    <slot />

    <!-- 👉 Footer -->
    <template #footer>
      <Footer />
    </template>

  </HorizontalNavLayout>
</template>
