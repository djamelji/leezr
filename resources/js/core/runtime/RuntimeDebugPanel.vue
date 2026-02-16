<script setup>
import { useRuntimeStore } from './runtime'
import { cacheEntries } from './cache'

const runtime = useRuntimeStore()
const isOpen = ref(false)
const cachedItems = ref([])
const journalFilter = ref('')
const stressRunning = ref(false)
const stressResult = ref(null)

async function runStress() {
  if (stressRunning.value) return
  stressRunning.value = true
  stressResult.value = null

  try {
    const { runStressTests } = await import('@/devtools/runtimeStress')
    stressResult.value = await runStressTests()
  }
  catch (err) {
    stressResult.value = { summary: { allPass: false }, error: err.message }
  }
  finally {
    stressRunning.value = false
  }
}

const filteredJournal = computed(() => {
  const entries = runtime.journalEntries
  const last20 = entries.slice(-20).reverse()

  if (!journalFilter.value) return last20

  return last20.filter(e => e.type.includes(journalFilter.value))
})

const journalTypes = computed(() => {
  const types = new Set(runtime.journalEntries.map(e => e.type))

  return ['', ...types]
})

// Toggle with Ctrl+Shift+D
const handleKeydown = e => {
  if (e.ctrlKey && e.shiftKey && e.key === 'D') {
    e.preventDefault()
    isOpen.value = !isOpen.value
    if (isOpen.value) {
      cachedItems.value = cacheEntries()
    }
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})

// Refresh cache entries when panel is open
watch(isOpen, open => {
  if (open) {
    cachedItems.value = cacheEntries()
  }
})

const phaseColor = computed(() => {
  const colors = {
    cold: 'grey',
    auth: 'info',
    tenant: 'warning',
    features: 'primary',
    ready: 'success',
    error: 'error',
  }

  return colors[runtime.phase] || 'grey'
})

const resourceEntries = computed(() => {
  return Object.entries(runtime.resourceStatus).map(([key, status]) => ({
    key,
    status,
    color: status === 'done' ? 'success' : status === 'loading' ? 'info' : status === 'error' ? 'error' : 'grey',
  }))
})

const formatAge = ms => {
  if (ms < 1000) return `${ms}ms`
  if (ms < 60000) return `${Math.round(ms / 1000)}s`

  return `${Math.round(ms / 60000)}m`
}
</script>

<template>
  <Teleport to="body">
    <Transition name="slide">
      <div
        v-if="isOpen"
        class="runtime-debug-panel"
      >
        <div class="d-flex align-center justify-space-between pa-3 bg-surface border-b">
          <span class="text-subtitle-2 font-weight-bold">Runtime Debug</span>
          <VBtn
            icon
            size="x-small"
            variant="text"
            @click="isOpen = false"
          >
            <VIcon
              icon="tabler-x"
              size="16"
            />
          </VBtn>
        </div>

        <div class="pa-3 overflow-y-auto" style="max-height: 400px;">
          <!-- Phase + Scope -->
          <div class="mb-3">
            <div class="text-caption text-disabled mb-1">
              Phase
            </div>
            <VChip
              :color="phaseColor"
              size="small"
              class="me-2"
            >
              {{ runtime.phase }}
            </VChip>
            <VChip
              size="small"
              variant="outlined"
            >
              {{ runtime.scope || 'none' }}
            </VChip>
          </div>

          <!-- Resources -->
          <div
            v-if="resourceEntries.length"
            class="mb-3"
          >
            <div class="text-caption text-disabled mb-1">
              Resources
            </div>
            <div
              v-for="r in resourceEntries"
              :key="r.key"
              class="d-flex align-center gap-2 mb-1"
            >
              <VIcon
                :icon="r.status === 'done' ? 'tabler-check' : r.status === 'loading' ? 'tabler-loader' : r.status === 'error' ? 'tabler-alert-triangle' : 'tabler-clock'"
                :color="r.color"
                size="14"
              />
              <span class="text-caption">{{ r.key }}</span>
            </div>
          </div>

          <!-- Cache entries -->
          <div
            v-if="cachedItems.length"
            class="mb-3"
          >
            <div class="text-caption text-disabled mb-1">
              Cache ({{ cachedItems.length }})
            </div>
            <div
              v-for="c in cachedItems"
              :key="c.key"
              class="d-flex align-center gap-2 mb-1"
            >
              <VIcon
                :icon="c.versionMatch ? 'tabler-database' : 'tabler-database-off'"
                :color="c.versionMatch ? 'success' : 'warning'"
                size="14"
              />
              <span class="text-caption">{{ c.key }}</span>
              <span class="text-caption text-disabled">{{ formatAge(c.age) }}</span>
            </div>
          </div>

          <!-- Event Journal -->
          <div v-if="runtime.journalEntries.length">
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-caption text-disabled">Journal ({{ runtime.journalEntries.length }})</span>
              <VBtn
                variant="text"
                size="x-small"
                @click="runtime.clearJournal()"
              >
                Clear
              </VBtn>
            </div>
            <select
              v-model="journalFilter"
              class="text-caption mb-2"
              style="width: 100%; background: transparent; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 4px; padding: 2px 4px;"
            >
              <option
                v-for="t in journalTypes"
                :key="t"
                :value="t"
              >
                {{ t || 'All events' }}
              </option>
            </select>
            <div
              v-for="(entry, idx) in filteredJournal"
              :key="idx"
              class="text-caption mb-1"
            >
              <VIcon
                :icon="entry.type.startsWith('phase') ? 'tabler-arrow-right' : entry.type.startsWith('broadcast') ? 'tabler-broadcast' : 'tabler-point'"
                size="12"
                class="me-1"
              />
              <span class="font-weight-medium">{{ entry.type }}</span>
              <span
                v-if="entry.data.from && entry.data.to"
                class="text-disabled"
              > {{ entry.data.from }}→{{ entry.data.to }}</span>
              <span
                v-else-if="entry.data.event"
                class="text-disabled"
              > {{ entry.data.event }}</span>
              <span class="text-disabled"> {{ formatAge(Date.now() - entry.ts) }}</span>
            </div>
          </div>
        </div>

        <!-- Stress Tests -->
        <div class="pa-3 border-t">
          <div class="d-flex align-center justify-space-between mb-1">
            <span class="text-caption text-disabled">Stress Harness</span>
            <VBtn
              size="x-small"
              variant="tonal"
              :color="stressResult?.summary?.allPass ? 'success' : stressResult ? 'error' : 'primary'"
              :loading="stressRunning"
              @click="runStress"
            >
              Run Stress
            </VBtn>
          </div>
          <div
            v-if="stressResult?.scenarios"
            class="mt-1"
          >
            <div
              v-for="s in stressResult.scenarios"
              :key="s.name"
              class="d-flex align-center gap-2 mb-1"
            >
              <VIcon
                :icon="s.pass ? 'tabler-check' : 'tabler-x'"
                :color="s.pass ? 'success' : 'error'"
                size="14"
              />
              <span class="text-caption">{{ s.name }}</span>
            </div>
          </div>
        </div>

        <div class="pa-2 bg-surface border-t text-center">
          <span class="text-caption text-disabled">Ctrl+Shift+D to toggle</span>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.runtime-debug-panel {
  position: fixed;
  bottom: 16px;
  right: 16px;
  width: 320px;
  max-height: 500px;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
  z-index: 99999;
  overflow: hidden;
}

.slide-enter-active,
.slide-leave-active {
  transition: all 0.2s ease;
}

.slide-enter-from,
.slide-leave-to {
  opacity: 0;
  transform: translateY(16px);
}
</style>
