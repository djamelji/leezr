<script setup>
import staticNavItems from '@/navigation/horizontal'
import { useModuleStore } from '@/core/stores/module'

// Components
import Footer from '@/layouts/components/Footer.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'
import { HorizontalNavLayout } from '@layouts'

const moduleStore = useModuleStore()

const navItems = computed(() => {
  const moduleNavItems = moduleStore.activeNavItems.map(item => ({
    title: item.title,
    to: item.to,
    icon: { icon: item.icon },
  }))

  if (moduleStore._loaded && moduleNavItems.length > 0) {
    const moduleRouteNames = new Set(moduleNavItems.map(i => i.to?.name).filter(Boolean))

    // Filter out core fallback items that modules now provide
    const items = staticNavItems.filter(item => {
      if (!item.to?.name) return true

      return !moduleRouteNames.has(item.to.name)
    })

    // Insert after Dashboard (index 0)
    items.splice(1, 0, ...moduleNavItems)

    return items
  }

  return [...staticNavItems]
})
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
