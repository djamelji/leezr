<script setup>
import { useRuntimeStore } from '@/core/runtime/runtime'

const runtime = useRuntimeStore()
const isDev = import.meta.env.DEV

const retry = async () => {
  const scope = runtime.scope || 'company'
  runtime.teardown()
  await runtime.boot(scope)
}
</script>

<template>
  <!-- Ready: render page content -->
  <div v-if="runtime.isReady">
    <slot />
  </div>

  <!-- Error: recoverable error with retry -->
  <div
    v-else-if="runtime.phase === 'error'"
    class="d-flex align-center justify-center"
    style="min-height: 400px;"
  >
    <VCard
      max-width="500"
      class="text-center pa-8"
    >
      <VIcon
        icon="tabler-alert-circle"
        size="64"
        color="error"
        class="mb-4"
      />

      <VCardTitle class="text-h5 mb-2">
        {{ $t('runtime.error.title', 'Unable to load the application') }}
      </VCardTitle>

      <VCardText class="text-body-1 mb-4">
        {{ runtime.error }}
      </VCardText>

      <VBtn
        color="primary"
        @click="retry"
      >
        {{ $t('runtime.error.retry', 'Try again') }}
      </VBtn>
    </VCard>
  </div>

  <!-- Booting: phase-specific loading message -->
  <div
    v-else
    class="d-flex flex-column align-center justify-center"
    style="min-height: 400px;"
  >
    <VProgressCircular
      indeterminate
      color="primary"
      size="48"
      class="mb-4"
    />

    <p class="text-body-1 text-medium-emphasis">
      {{ runtime.phaseMessage }}
    </p>

    <!-- Dev: show raw phase + scope -->
    <p
      v-if="isDev"
      class="text-caption text-disabled mt-2"
    >
      Phase: {{ runtime.phase }} | Scope: {{ runtime.scope }}
    </p>
  </div>
</template>
