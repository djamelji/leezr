import { useTheme } from 'vuetify'
import { VThemeProvider } from 'vuetify/components/VThemeProvider'
import { useConfigStore } from '@core/stores/config'
import { AppContentLayoutNav } from '@layouts/enums'

// TODO: Use `VThemeProvider` from dist instead of lib (Using this component from dist causes navbar to loose sticky positioning)
export const useSkins = () => {
  const configStore = useConfigStore()
  const vuetifyTheme = useTheme()

  const layoutAttrs = computed(() => {
    if (!configStore.isVerticalNavSemiDark || configStore.appContentLayoutNav !== AppContentLayoutNav.Vertical)
      return { verticalNavAttrs: { wrapper: h(VThemeProvider, { tag: 'div' }), wrapperProps: { withBackground: true } } }

    const oppositeTheme = vuetifyTheme.global.name.value === 'dark' ? 'light' : 'dark'

    return {
      verticalNavAttrs: {
        wrapper: h(VThemeProvider, { tag: 'div' }),
        wrapperProps: {
          withBackground: true,
          theme: oppositeTheme,
        },
      },
    }
  })

  const injectSkinClasses = () => {
    if (typeof document !== 'undefined') {
      const bodyClasses = document.body.classList
      const genSkinClass = _skin => `skin--${_skin}`

      watch(() => configStore.skin, (val, oldVal) => {
        bodyClasses.remove(genSkinClass(oldVal))
        bodyClasses.add(genSkinClass(val))
      }, { immediate: true })
    }
  }

  return {
    injectSkinClasses,
    layoutAttrs,
  }
}
