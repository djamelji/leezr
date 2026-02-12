<script setup>
import staticNavItems from '@/navigation/horizontal'
import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'
import { themeConfig } from '@themeConfig'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NavSearchBar from '@/layouts/components/NavSearchBar.vue'
import NavbarShortcuts from '@/layouts/components/NavbarShortcuts.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'
import NavBarI18n from '@core/components/I18n.vue'
import { HorizontalNavLayout } from '@layouts'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'

const authStore = useAuthStore()
const moduleStore = useModuleStore()
const router = useRouter()
const route = useRoute()

// Re-fetch modules when company changes (via switcher)
watch(() => authStore.currentCompanyId, async (newId, oldId) => {
  if (newId && newId !== oldId) {
    moduleStore.reset()
    try {
      await moduleStore.fetchModules()
    }
    catch {
      // fallback to static nav
    }

    // If current route requires a module that's no longer active, redirect
    if (route.meta.module && !moduleStore.isActive(route.meta.module)) {
      router.push('/')
    }
  }
})

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
        class="app-logo d-flex align-center gap-x-3"
      >
        <VNodeRenderer :nodes="themeConfig.app.logo" />

        <h1 class="app-title font-weight-bold leading-normal text-xl text-capitalize">
          {{ themeConfig.app.title }}
        </h1>
      </RouterLink>
      <VSpacer />

      <NavSearchBar trigger-btn-class="ms-lg-n3" />

      <NavBarI18n
        v-if="themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length"
        :languages="themeConfig.app.i18n.langConfig"
      />

      <NavbarThemeSwitcher />
      <NavbarShortcuts />
      <NavBarNotifications class="me-2" />
      <UserProfile />
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
