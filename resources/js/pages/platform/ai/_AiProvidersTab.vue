<script setup>
import { usePlatformAiStore } from '@/modules/platform-admin/ai/ai.store'
import { useAppToast } from '@/composables/useAppToast'
import AiProviderConfigDrawer from './_AiProviderConfigDrawer.vue'

const { t } = useI18n()
const store = usePlatformAiStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const healthCheckLoading = ref({})
const installLoading = ref({})
const isConfigDrawerOpen = ref(false)
const selectedProvider = ref(null)

const installColor = provider => {
  if (provider.is_active) return 'success'
  if (provider.is_installed) return 'info'

  return 'secondary'
}

const installLabel = provider => {
  if (provider.is_active) return t('platformAi.status.active')
  if (provider.is_installed) return t('platformAi.status.installed')

  return t('platformAi.status.notInstalled')
}

const configColor = status => {
  const map = { active: 'success', misconfigured: 'warning', disabled: 'secondary' }

  return map[status] || 'default'
}

const healthColor = status => {
  const map = { healthy: 'success', degraded: 'warning', down: 'error' }

  return map[status] || 'default'
}

const capabilityIcon = cap => {
  const map = { vision: 'tabler-eye', completion: 'tabler-message-chatbot', text_extraction: 'tabler-text-recognition' }

  return map[cap] || 'tabler-cpu'
}

const load = async () => {
  isLoading.value = true
  try {
    await store.fetchProviders()
  }
  catch {
    toast(t('common.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

const installProvider = async provider => {
  installLoading.value[provider.provider_key] = true
  try {
    await store.installProvider(provider.provider_key)
    toast(t('platformAi.providerInstalled'), 'success')
  }
  catch {
    toast(t('common.error'), 'error')
  }
  finally {
    installLoading.value[provider.provider_key] = false
  }
}

const toggleProvider = async provider => {
  try {
    if (provider.is_active) {
      await store.deactivateProvider(provider.provider_key)
      toast(t('platformAi.providerDeactivated'), 'success')
    }
    else {
      await store.activateProvider(provider.provider_key)
      toast(t('platformAi.providerActivated'), 'success')
    }
  }
  catch {
    toast(t('common.error'), 'error')
  }
}

const openConfigDrawer = provider => {
  selectedProvider.value = provider
  isConfigDrawerOpen.value = true
}

const runHealthCheck = async provider => {
  healthCheckLoading.value[provider.provider_key] = true
  try {
    await store.runHealthCheck(provider.provider_key)
    toast(t('platformAi.healthCheckDone'), 'success')
  }
  catch {
    toast(t('common.error'), 'error')
  }
  finally {
    healthCheckLoading.value[provider.provider_key] = false
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <VCard
      v-if="isLoading"
      :loading="true"
    >
      <VCardText class="text-center pa-6">
        {{ t('common.loading') }}
      </VCardText>
    </VCard>

    <VRow
      v-else
      class="card-grid card-grid-md"
    >
      <VCol
        v-for="provider in store.providers"
        :key="provider.provider_key"
        cols="12"
        md="6"
      >
        <VCard
          flat
          border
          :style="provider.is_active ? 'border-color: rgb(var(--v-theme-primary))' : ''"
        >
          <VCardText>
            <!-- Header: Avatar + Name + Description -->
            <div class="d-flex align-center gap-3 mb-3">
              <VAvatar
                size="40"
                variant="tonal"
                :color="provider.is_active ? 'primary' : 'secondary'"
                rounded
              >
                <VIcon
                  :icon="provider.icon_ref"
                  size="22"
                />
              </VAvatar>
              <div>
                <h6 class="text-h6">
                  {{ provider.name }}
                </h6>
                <span class="text-body-2 text-medium-emphasis">{{ provider.description }}</span>
              </div>
            </div>

            <!-- Capabilities chips -->
            <div
              v-if="provider.supported_capabilities?.length"
              class="d-flex gap-1 flex-wrap mb-3"
            >
              <VChip
                v-for="cap in provider.supported_capabilities"
                :key="cap"
                size="x-small"
                variant="tonal"
                color="primary"
              >
                <VIcon
                  start
                  size="14"
                  :icon="capabilityIcon(cap)"
                />
                {{ t(`platformAi.capability.${cap}`) }}
              </VChip>
            </div>

            <!-- Status line: install state + config status + health -->
            <div class="d-flex gap-2 flex-wrap">
              <VChip
                size="small"
                variant="tonal"
                :color="installColor(provider)"
              >
                {{ installLabel(provider) }}
              </VChip>
              <VChip
                v-if="provider.is_installed && provider.configuration_status"
                size="small"
                variant="tonal"
                :color="configColor(provider.configuration_status)"
              >
                {{ t(`platformAi.configStatus.${provider.configuration_status}`) }}
              </VChip>
              <VChip
                v-if="provider.is_installed && provider.health_status && provider.health_status !== 'unknown'"
                size="small"
                variant="tonal"
                :color="healthColor(provider.health_status)"
              >
                {{ provider.health_status }}
              </VChip>
            </div>
          </VCardText>

          <VSpacer />
          <VDivider />

          <!-- Actions -->
          <VCardActions class="pa-4">
            <template v-if="!provider.is_installed">
              <VBtn
                size="small"
                color="primary"
                :loading="installLoading[provider.provider_key]"
                @click="installProvider(provider)"
              >
                <VIcon
                  start
                  icon="tabler-download"
                />
                {{ t('platformAi.install') }}
              </VBtn>
            </template>

            <template v-else>
              <VBtn
                size="small"
                :color="provider.is_active ? 'warning' : 'success'"
                variant="tonal"
                @click="toggleProvider(provider)"
              >
                {{ provider.is_active ? t('platformAi.deactivate') : t('platformAi.activate') }}
              </VBtn>

              <VBtn
                v-if="provider.requires_credentials"
                size="small"
                variant="tonal"
                @click="openConfigDrawer(provider)"
              >
                <VIcon
                  start
                  icon="tabler-settings"
                />
                {{ t('platformAi.configure') }}
              </VBtn>

              <VBtn
                size="small"
                variant="tonal"
                :loading="healthCheckLoading[provider.provider_key]"
                @click="runHealthCheck(provider)"
              >
                <VIcon icon="tabler-heartbeat" />
              </VBtn>
            </template>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <!-- Config Drawer -->
    <AiProviderConfigDrawer
      v-model:isDrawerOpen="isConfigDrawerOpen"
      :provider="selectedProvider"
      @saved="load"
    />
  </div>
</template>
