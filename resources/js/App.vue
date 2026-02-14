<script setup>
import { useTheme } from 'vuetify'
import ScrollToTop from '@core/components/ScrollToTop.vue'
import initCore from '@core/initCore'
import {
  initConfigStore,
  useConfigStore,
} from '@core/stores/config'
import { hexToRgb } from '@core/utils/colorConverter'
import { useAppToast } from '@/composables/useAppToast'

const { global } = useTheme()

// ℹ️ Sync current theme with initial loader theme
initCore()
initConfigStore()

const configStore = useConfigStore()
const { state: toastState } = useAppToast()

// Dev-only runtime debug panel
const RuntimeDebugPanel = import.meta.env.DEV
  ? defineAsyncComponent(() => import('@/core/runtime/RuntimeDebugPanel.vue'))
  : null
</script>

<template>
  <VLocaleProvider :rtl="configStore.isAppRTL">
    <!-- ℹ️ This is required to set the background color of active nav link based on currently active global theme's primary -->
    <VApp :style="`--v-global-theme-primary: ${hexToRgb(global.current.value.colors.primary)}`">
      <RouterView />

      <ScrollToTop />

      <!-- Dev-only runtime debug panel (Ctrl+Shift+D) -->
      <component
        :is="RuntimeDebugPanel"
        v-if="RuntimeDebugPanel"
      />

      <VSnackbar
        v-model="toastState.show"
        :color="toastState.color"
        :timeout="4000"
        location="top end"
      >
        {{ toastState.message }}
      </VSnackbar>
    </VApp>
  </VLocaleProvider>
</template>
