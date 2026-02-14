<script setup>
import { switchToVerticalNavOnLtOverlayNavBreakpoint } from '@layouts/utils'
import AppShellGate from './components/AppShellGate.vue'

const PlatformLayoutWithVerticalNav = defineAsyncComponent(() => import('./components/PlatformLayoutWithVerticalNav.vue'))

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
  <PlatformLayoutWithVerticalNav v-bind="layoutAttrs">
    <AppLoadingIndicator ref="refLoadingIndicator" />

    <AppShellGate>
      <RouterView v-slot="{ Component }">
        <Suspense
          :timeout="0"
          @fallback="isFallbackStateActive = true"
          @resolve="isFallbackStateActive = false"
        >
          <Component :is="Component" />
        </Suspense>
      </RouterView>
    </AppShellGate>
  </PlatformLayoutWithVerticalNav>
</template>

<style lang="scss">
@use "@layouts/styles/default-layout";
</style>
