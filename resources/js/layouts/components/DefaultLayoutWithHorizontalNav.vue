<script setup>
import { useCompanyNav } from '@/composables/useCompanyNav'

// Components
import Footer from '@/layouts/components/Footer.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'
import { HorizontalNavLayout } from '@layouts'

const { navItems } = useCompanyNav()
</script>

<template>
  <HorizontalNavLayout :nav-items="navItems">
    <!-- 👉 navbar -->
    <template #navbar>
      <RouterLink
        to="/"
        class="app-logo d-flex align-center gap-x-3 text-decoration-none"
      >
        <BrandLogo size="md" />
      </RouterLink>
      <VSpacer />

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
