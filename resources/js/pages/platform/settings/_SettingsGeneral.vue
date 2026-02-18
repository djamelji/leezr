<script setup>
const appName = import.meta.env.VITE_APP_NAME || 'Leezr'

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

        <VAlert
          type="info"
          variant="tonal"
          class="mt-4"
        >
          General settings will be expanded in future releases.
        </VAlert>
      </VCardText>
    </VCard>
  </div>
</template>
