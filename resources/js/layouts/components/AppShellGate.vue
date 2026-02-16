<script setup>
import { useRuntimeStore } from '@/core/runtime/runtime'

const runtime = useRuntimeStore()
const isDev = import.meta.env.DEV

// ─── Timeout detection (8s without reaching ready) ─────
const isTimeout = ref(false)
let timeoutTimer = null

watch(() => runtime.phase, phase => {
  clearTimeout(timeoutTimer)
  isTimeout.value = false

  if (['auth', 'tenant', 'features'].includes(phase)) {
    timeoutTimer = setTimeout(() => {
      if (!runtime.isReady && runtime.phase !== 'error') {
        isTimeout.value = true
      }
    }, 8000)
  }
}, { immediate: true })

onUnmounted(() => clearTimeout(timeoutTimer))

// ─── Progress ──────────────────────────────────────────
const progressPercent = computed(() => {
  const p = runtime.progress
  if (!p.total) return 0

  return Math.round((p.done / p.total) * 100)
})

// ─── Retry actions ─────────────────────────────────────
const retryPartial = () => runtime.retryFailed()

const retryFull = () => {
  const scope = runtime.scope || 'company'
  runtime.teardown()
  runtime.boot(scope)
}

// ─── DEV overlay ────────────────────────────────────────
const lastEvents = computed(() => {
  if (!isDev) return []
  const entries = runtime.journalEntries

  return entries.slice(-3).reverse()
})
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

      <div class="d-flex gap-3 justify-center">
        <VBtn
          color="primary"
          @click="retryPartial"
        >
          {{ $t('runtime.error.retry', 'Retry') }}
        </VBtn>

        <VBtn
          variant="outlined"
          @click="retryFull"
        >
          {{ $t('runtime.error.retryFull', 'Full reload') }}
        </VBtn>
      </div>

      <!-- Dev: show resource status + runId + last events -->
      <div
        v-if="isDev"
        class="text-caption text-disabled mt-4 text-start"
      >
        <p class="mb-1">
          Run: {{ runtime.currentRunId ?? '-' }}
        </p>
        <div
          v-for="(status, key) in runtime.resourceStatus"
          :key="key"
        >
          {{ key }}: {{ status }}
        </div>
        <div
          v-if="lastEvents.length"
          class="mt-1"
        >
          <p
            v-for="(e, i) in lastEvents"
            :key="i"
          >
            {{ e.type }}<template v-if="e.data.from"> {{ e.data.from }}→{{ e.data.to }}</template><template v-else-if="e.data.key"> {{ e.data.key }}</template>
          </p>
        </div>
      </div>
    </VCard>
  </div>

  <!-- Booting: phase-specific loading with progress -->
  <div
    v-else
    class="d-flex flex-column align-center justify-center"
    style="min-height: 400px;"
  >
    <div style="width: 300px; max-width: 80%;">
      <VProgressLinear
        :model-value="progressPercent"
        color="primary"
        height="6"
        rounded
        class="mb-4"
      />
    </div>

    <p class="text-body-1 text-medium-emphasis">
      {{ runtime.phaseMessage }}
    </p>

    <!-- Timeout warning -->
    <template v-if="isTimeout">
      <p class="text-body-2 text-medium-emphasis mt-2">
        {{ $t('runtime.timeout', 'This is taking longer than expected...') }}
      </p>

      <VBtn
        variant="text"
        size="small"
        color="primary"
        class="mt-2"
        @click="retryFull"
      >
        {{ $t('runtime.error.retry', 'Retry') }}
      </VBtn>
    </template>

    <!-- Dev: show phase + scope + runId + progress + last 3 events -->
    <div
      v-if="isDev"
      class="text-caption text-disabled mt-4"
    >
      <p>Phase: {{ runtime.phase }} | Scope: {{ runtime.scope }} | Run: {{ runtime.currentRunId ?? '-' }}</p>
      <p>Progress: {{ runtime.progress.done }}/{{ runtime.progress.total }} ({{ runtime.progress.loading }} loading, {{ runtime.progress.error }} errors)</p>
      <div
        v-if="lastEvents.length"
        class="mt-1"
      >
        <p
          v-for="(e, i) in lastEvents"
          :key="i"
        >
          {{ e.type }}<template v-if="e.data.from"> {{ e.data.from }}→{{ e.data.to }}</template><template v-else-if="e.data.key"> {{ e.data.key }}</template>
        </p>
      </div>
    </div>
  </div>
</template>
