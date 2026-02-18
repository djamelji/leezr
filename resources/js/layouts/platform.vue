<script setup>
import { useConfigStore } from '@core/stores/config'
import { AppContentLayoutNav } from '@layouts/enums'
import { switchToVerticalNavOnLtOverlayNavBreakpoint } from '@layouts/utils'
import AppShellGate from './components/AppShellGate.vue'

const PlatformLayoutWithVerticalNav = defineAsyncComponent(() => import('./components/PlatformLayoutWithVerticalNav.vue'))
const PlatformLayoutWithHorizontalNav = defineAsyncComponent(() => import('./components/PlatformLayoutWithHorizontalNav.vue'))

const configStore = useConfigStore()

switchToVerticalNavOnLtOverlayNavBreakpoint()

const { layoutAttrs, injectSkinClasses } = useSkins()

injectSkinClasses()

// SECTION: Loading Indicator
const isFallbackStateActive = ref(false)
const refLoadingIndicator = ref(null)

watch([
  isFallbackStateActive,
  refLoadingIndicator,
], () => {
  if (isFallbackStateActive.value && refLoadingIndicator.value)
    refLoadingIndicator.value.fallbackHandle()
  if (!isFallbackStateActive.value && refLoadingIndicator.value)
    refLoadingIndicator.value.resolveHandle()
}, { immediate: true })
// !SECTION
</script>

<template>
  <Component
    v-bind="layoutAttrs"
    :is="configStore.appContentLayoutNav === AppContentLayoutNav.Vertical ? PlatformLayoutWithVerticalNav : PlatformLayoutWithHorizontalNav"
  >
    <AppLoadingIndicator ref="refLoadingIndicator" />

    <AppShellGate>
      <RouterView v-slot="{ Component: page }">
        <Suspense
          :timeout="0"
          @fallback="isFallbackStateActive = true"
          @resolve="isFallbackStateActive = false"
        >
          <Component :is="page" />
        </Suspense>
      </RouterView>
    </AppShellGate>
  </Component>
</template>

<style lang="scss">
@use "@layouts/styles/default-layout";
</style>
