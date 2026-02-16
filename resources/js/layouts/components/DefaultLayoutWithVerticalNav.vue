<script setup>
import staticNavItems, { coreNavItems } from '@/navigation/vertical'
import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'
// Components
import Footer from '@/layouts/components/Footer.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

const auth = useAuthStore()
const moduleStore = useModuleStore()

// Route names from core fallback items (for deduplication)
const coreRouteNames = new Set(coreNavItems.map(i => i.to?.name).filter(Boolean))

/**
 * Build nav: static base + module items.
 * Before modules load: core fallback items are visible (Members, Settings).
 * After modules load: replace core fallbacks with module-driven items,
 * which may include additional items (Shipments, etc.).
 * Then filter by permission + ownerOnly based on current user's RBAC.
 */
const navItems = computed(() => {
  const moduleNavItems = moduleStore.activeNavItems.map(item => ({
    title: item.title,
    to: item.to,
    icon: { icon: item.icon },
    permission: item.permission,
  }))

  let items

  // If modules loaded, filter out static core items that modules now provide
  if (moduleStore._loaded && moduleNavItems.length > 0) {
    const moduleRouteNames = new Set(moduleNavItems.map(i => i.to?.name).filter(Boolean))

    items = staticNavItems.filter(item => {
      if (!item.to?.name) return true

      // Keep static item unless a module provides it
      return !moduleRouteNames.has(item.to.name)
    })

    // Insert module items after "Company" heading
    const companyIdx = items.findIndex(i => i.heading === 'Company')
    if (companyIdx !== -1)
      items.splice(companyIdx + 1, 0, ...moduleNavItems)
    else
      items.push(...moduleNavItems)
  }
  else {
    // Fallback: static items as-is (includes core Members/Settings)
    items = [...staticNavItems]
  }

  // Filter by permission + ownerOnly
  return items.filter(item => {
    if (item.heading) return true
    if (item.ownerOnly && !auth.isOwner) return false
    if (item.permission && !auth.hasPermission(item.permission)) return false

    return true
  }).filter((item, index, arr) => {
    // Remove orphan headings (heading followed by another heading or end)
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
