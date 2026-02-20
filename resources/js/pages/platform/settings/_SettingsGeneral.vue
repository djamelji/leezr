<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

const platformAuth = usePlatformAuthStore()

const meta = computed(() => platformAuth.appMeta || {})

const versionLabel = computed(() => {
  const v = meta.value.version
  return v && v !== 'dev' ? `v${v}` : 'dev'
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

const items = computed(() => [
  { icon: 'tabler-app-window', label: 'Application', value: 'Leezr' },
  { icon: 'tabler-versions', label: 'Version', value: versionLabel.value },
  { icon: 'tabler-server', label: 'Environment', value: envLabel.value },
  { icon: 'tabler-hash', label: 'Build', value: meta.value.build_number ? `#${meta.value.build_number}` : null },
  { icon: 'tabler-git-commit', label: 'Commit', value: meta.value.commit_hash || null },
  { icon: 'tabler-calendar-event', label: 'Build Date', value: buildDateLabel.value },
  { icon: 'tabler-clock-up', label: 'Uptime', value: meta.value.uptime || null },
])
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-settings"
          class="me-2"
        />
        General Settings
      </VCardTitle>
      <VCardSubtitle>
        Platform-wide configuration and information.
      </VCardSubtitle>

      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <VList>
              <template
                v-for="item in items"
                :key="item.label"
              >
                <VListItem v-if="item.value">
                  <template #prepend>
                    <VIcon
                      :icon="item.icon"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle>{{ item.label }}</VListItemTitle>
                  <VListItemSubtitle>{{ item.value }}</VListItemSubtitle>
                </VListItem>
              </template>
            </VList>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
