<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppName, setAppName } from '@/composables/useAppName'
import { $platformApi } from '@/utils/platformApi'

const platformAuth = usePlatformAuthStore()
const meta = computed(() => platformAuth.appMeta || {})
const appName = useAppName()

const brandNameEl = ref(null)
const saving = ref(false)
const saved = ref(false)

onMounted(() => {
  if (brandNameEl.value)
    brandNameEl.value.textContent = appName.value.toLowerCase()
})

watch(appName, val => {
  if (brandNameEl.value && brandNameEl.value !== document.activeElement)
    brandNameEl.value.textContent = val.toLowerCase()
})

async function onBrandBlur(event) {
  const raw = event.target.textContent.trim()
  if (!raw || raw.length > 50) {
    event.target.textContent = appName.value.toLowerCase()

    return
  }

  const name = raw.charAt(0).toUpperCase() + raw.slice(1)

  if (name === appName.value) return

  saving.value = true
  saved.value = false

  try {
    await $platformApi('/general-settings', {
      method: 'PUT',
      body: { app_name: name },
    })

    setAppName(name)
    saved.value = true
    setTimeout(() => { saved.value = false }, 3000)
  }
  finally {
    saving.value = false
  }
}

function onPaste(event) {
  event.preventDefault()
  document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '')
}

const versionLabel = computed(() => {
  const v = meta.value.version

  return v && v !== 'dev' ? `V ${v}` : 'dev'
})

const envLabel = computed(() => {
  const host = window.location.hostname
  if (host === 'leezr.com') return 'Production'
  if (host === 'dev.leezr.com') return 'Developpement'

  return 'Local'
})

const buildDateLabel = computed(() => {
  const raw = meta.value.build_date
  if (!raw) return null
  try {
    return new Date(raw).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' })
  }
  catch { return raw }
})

const statItems = computed(() => [
  { label: 'Build', value: meta.value.build_number ? `#${meta.value.build_number}` : '—' },
  { label: 'Commit', value: meta.value.commit_hash || '—' },
  { label: 'Build Date', value: buildDateLabel.value || '—' },
  { label: 'Uptime', value: meta.value.uptime || '—' },
])
</script>

<template>
  <VCard>
    <VCardText class="pa-0">
      <div class="d-flex flex-column flex-md-row">
        <!-- Brand section -->
        <div class="d-flex flex-column align-center justify-center pa-6 brand-section">
          <span
            class="brand-editable"
            :style="{ fontSize: '42px', fontWeight: 700, lineHeight: 1 }"
          >
            <span
              ref="brandNameEl"
              contenteditable="plaintext-only"
              class="brand-name"
              @blur="onBrandBlur"
              @paste="onPaste"
              @keydown.enter.prevent="$event.target.blur()"
            />
            <span :style="{ color: 'rgb(var(--v-theme-primary))', fontWeight: 700 }">.</span>
          </span>

          <span
            v-if="saving"
            class="text-body-2 text-medium-emphasis mt-2"
          >
            Saving...
          </span>
          <span
            v-else-if="saved"
            class="text-body-2 text-success mt-2"
          >
            Saved!
          </span>
          <span
            v-else
            class="text-body-2 text-medium-emphasis mt-2"
          >
            {{ versionLabel }} — {{ envLabel }}
          </span>
        </div>

        <VDivider :vertical="$vuetify.display.mdAndUp" />

        <!-- Stats section -->
        <div class="d-flex flex-wrap flex-grow-1 align-center">
          <template
            v-for="(item, i) in statItems"
            :key="item.label"
          >
            <div class="stat-item d-flex flex-column align-center justify-center pa-4 text-center">
              <span class="text-caption text-uppercase text-medium-emphasis font-weight-medium">
                {{ item.label }}
              </span>
              <span class="text-body-1 font-weight-medium mt-1">
                {{ item.value }}
              </span>
            </div>

            <VDivider
              v-if="i < statItems.length - 1"
              vertical
              class="d-none d-md-block align-self-stretch"
            />
          </template>
        </div>
      </div>
    </VCardText>
  </VCard>
</template>

<style lang="scss" scoped>
.brand-section {
  min-inline-size: 200px;
}

.brand-name {
  outline: none;
  border-radius: 4px;
  color: rgb(var(--v-theme-on-surface));
  transition: box-shadow 0.15s;

  &:hover {
    box-shadow: 0 0 0 2px rgba(var(--v-theme-on-surface), 0.08);
  }

  &:focus {
    box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.3);
  }
}

.stat-item {
  flex: 1 1 auto;
  min-inline-size: 120px;
}

// Mobile: 2 stats per row
@media (max-width: 959.98px) {
  .stat-item {
    flex: 0 0 50%;
  }
}
</style>
