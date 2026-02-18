<script setup>
import { useConfigStore } from '@core/stores/config'
import { AppContentLayoutNav } from '@layouts/enums'
import { switchToVerticalNavOnLtOverlayNavBreakpoint } from '@layouts/utils'
import { useSessionGovernance } from '@/composables/useSessionGovernance'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useRuntimeStore } from '@/core/runtime/runtime'
import AppShellGate from './components/AppShellGate.vue'
import SessionTimeoutWarning from './components/SessionTimeoutWarning.vue'

const PlatformLayoutWithVerticalNav = defineAsyncComponent(() => import('./components/PlatformLayoutWithVerticalNav.vue'))
const PlatformLayoutWithHorizontalNav = defineAsyncComponent(() => import('./components/PlatformLayoutWithHorizontalNav.vue'))

const configStore = useConfigStore()

switchToVerticalNavOnLtOverlayNavBreakpoint()

const { layoutAttrs, injectSkinClasses } = useSkins()

injectSkinClasses()

// SECTION: Session Governance
const platformAuth = usePlatformAuthStore()
const runtime = useRuntimeStore()
const session = useSessionGovernance()

watch(() => [runtime.isReady, platformAuth.sessionConfig], ([ready, config]) => {
  if (ready && config) {
    session.start({
      scope: 'platform',
      config,
      async onLogout() {
        await platformAuth.logout()
        window.location.href = '/platform/login'
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

  <SessionTimeoutWarning
    :is-dialog-visible="session.isWarningVisible.value"
    :remaining-seconds="session.remainingSeconds.value"
    @extend="session.extendSession"
  />
</template>

<style lang="scss">
@use "@layouts/styles/default-layout";
</style>
