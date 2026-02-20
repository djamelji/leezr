<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

const platformAuth = usePlatformAuthStore()

const appName = 'Leezr'

const versionLabel = computed(() => {
  const v = platformAuth.appVersion
  return v && v !== 'dev' ? `Leezr v${v}` : 'Leezr (dev)'
})

const envLabel = computed(() => {
  const host = window.location.hostname
  if (host === 'leezr.com') return 'Production'
  if (host === 'dev.leezr.com') return 'Developpement'

  return 'Local'
})
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
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-app-window"
                    class="me-2"
                  />
                </template>
                <VListItemTitle>Application</VListItemTitle>
                <VListItemSubtitle>{{ appName }}</VListItemSubtitle>
              </VListItem>

              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-version"
                    class="me-2"
                  />
                </template>
                <VListItemTitle>Version</VListItemTitle>
                <VListItemSubtitle>{{ versionLabel }}</VListItemSubtitle>
              </VListItem>

              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-server"
                    class="me-2"
                  />
                </template>
                <VListItemTitle>Environment</VListItemTitle>
                <VListItemSubtitle>{{ envLabel }}</VListItemSubtitle>
              </VListItem>
            </VList>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
