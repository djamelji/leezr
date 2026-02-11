<script setup>
import staticNavItems from '@/navigation/vertical'
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

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

const moduleStore = useModuleStore()

// Fetch modules on layout mount if not already loaded (e.g., page refresh)
onMounted(async () => {
  if (!moduleStore._loaded) {
    try {
      await moduleStore.fetchModules()
    }
    catch {
      // Non-blocking — nav will show static items only
    }
  }
})

/**
 * Merge static nav with dynamic module nav items.
 * Module items are inserted after the "Company" heading.
 */
const navItems = computed(() => {
  const items = [...staticNavItems]
  const moduleNavItems = moduleStore.activeNavItems.map(item => ({
    title: item.title,
    to: item.to,
    icon: { icon: item.icon },
  }))

  // Find the "Company" heading and insert module items after it
  const companyHeadingIndex = items.findIndex(i => i.heading === 'Company')
  if (companyHeadingIndex !== -1) {
    items.splice(companyHeadingIndex + 1, 0, ...moduleNavItems)
  }
  else {
    items.push(...moduleNavItems)
  }

  return items
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

        <NavBarI18n
          v-if="themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length"
          :languages="themeConfig.app.i18n.langConfig"
        />
        <NavbarThemeSwitcher />
        <NavbarShortcuts />
        <NavBarNotifications class="me-1" />
        <UserProfile />
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
