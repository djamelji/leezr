import { useStorage } from '@vueuse/core'
import { useConfigStore } from '@core/stores/config'
import { cookieRef, namespaceConfig, useLayoutConfigStore } from '@layouts/stores/config'
import { vuetifyInstance } from '@/plugins/vuetify'

/**
 * Apply a platform-governed UI theme to the frontend.
 *
 * Writes to the same cookieRef-backed stores that Vuexy reads.
 * Theme/skin/layout changes propagate immediately via existing watchers
 * in initConfigStore() and _handleSkinChanges().
 *
 * Primary colors are written to cookies AND mutated directly on the
 * Vuetify instance for immediate runtime effect (no reload needed).
 *
 * Called from auth store login() and fetchMe() when `ui_theme` is present,
 * and from the platform theme page after save/reset.
 */
export function applyTheme(payload) {
  if (!payload) return

  const configStore = useConfigStore()
  const layoutStore = useLayoutConfigStore()

  // Theme mode (light / dark / system) — watcher in initConfigStore() applies to Vuetify
  if (payload.theme) {
    configStore.theme = payload.theme
  }

  // Skin (default / bordered) — watcher in _handleSkinChanges() applies
  if (payload.skin) {
    configStore.skin = payload.skin
  }

  // Semi-dark vertical nav
  if (payload.semi_dark !== undefined) {
    configStore.isVerticalNavSemiDark = payload.semi_dark
  }

  // Layout (vertical / horizontal) — reactive via _layoutClasses computed
  if (payload.layout) {
    layoutStore.appContentLayoutNav = payload.layout
  }

  // Nav collapsed
  if (payload.nav_collapsed !== undefined) {
    layoutStore.isVerticalNavCollapsed = payload.nav_collapsed
  }

  // Navbar blur
  if (payload.navbar_blur !== undefined) {
    layoutStore.isNavbarBlurEnabled = payload.navbar_blur
  }

  // Content width (boxed / fluid)
  if (payload.content_width) {
    layoutStore.appContentWidth = payload.content_width
  }

  // Primary colors — cookies + direct Vuetify mutation + loader sync
  if (payload.primary_color) {
    cookieRef('lightThemePrimaryColor', payload.primary_color).value = payload.primary_color
    cookieRef('darkThemePrimaryColor', payload.primary_color).value = payload.primary_color

    if (vuetifyInstance) {
      vuetifyInstance.theme.themes.value.light.colors.primary = payload.primary_color
      vuetifyInstance.theme.themes.value.dark.colors.primary = payload.primary_color
    }

    useStorage(namespaceConfig('initial-loader-color'), null).value = payload.primary_color
  }

  if (payload.primary_darken_color) {
    cookieRef('lightThemePrimaryDarkenColor', payload.primary_darken_color).value = payload.primary_darken_color
    cookieRef('darkThemePrimaryDarkenColor', payload.primary_darken_color).value = payload.primary_darken_color

    if (vuetifyInstance) {
      vuetifyInstance.theme.themes.value.light.colors['primary-darken-1'] = payload.primary_darken_color
      vuetifyInstance.theme.themes.value.dark.colors['primary-darken-1'] = payload.primary_darken_color
    }
  }
}
