<script setup>
import { useConfigStore } from '@core/stores/config'
import { AppContentLayoutNav } from '@layouts/enums'
import { switchToVerticalNavOnLtOverlayNavBreakpoint } from '@layouts/utils'
import { useSessionGovernance } from '@/composables/useSessionGovernance'
import { useAuthStore } from '@/core/stores/auth'
import { useRuntimeStore } from '@/core/runtime/runtime'
import AppShellGate from './components/AppShellGate.vue'
import SessionTimeoutWarning from './components/SessionTimeoutWarning.vue'

const DefaultLayoutWithHorizontalNav = defineAsyncComponent(() => import('./components/DefaultLayoutWithHorizontalNav.vue'))
const DefaultLayoutWithVerticalNav = defineAsyncComponent(() => import('./components/DefaultLayoutWithVerticalNav.vue'))
const configStore = useConfigStore()

// ℹ️ This will switch to vertical nav when define breakpoint is reached when in horizontal nav layout

// Remove below composable usage if you are not using horizontal nav layout in your app
switchToVerticalNavOnLtOverlayNavBreakpoint()

const { layoutAttrs, injectSkinClasses } = useSkins()

injectSkinClasses()

// SECTION: Session Governance
const auth = useAuthStore()
const runtime = useRuntimeStore()
const session = useSessionGovernance()

watch(() => [runtime.isReady, auth.sessionConfig], ([ready, config]) => {
  if (ready && config) {
    session.start({
      scope: 'company',
      config,
      async onLogout() {
        await auth.logout()
        window.location.href = '/login'
      },
    })
  }
}, { immediate: true })

onUnmounted(() => session.stop())
// !SECTION

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
    :is="configStore.appContentLayoutNav === AppContentLayoutNav.Vertical ? DefaultLayoutWithVerticalNav : DefaultLayoutWithHorizontalNav"
  >
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
  </Component>

  <SessionTimeoutWarning
    :is-dialog-visible="session.isWarningVisible.value"
    :remaining-seconds="session.remainingSeconds.value"
    @extend="session.extendSession"
  />
</template>

<style lang="scss">
// As we are using `layouts` plugin we need its styles to be imported
@use "@layouts/styles/default-layout";
</style>
