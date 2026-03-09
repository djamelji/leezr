<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const actionLoading = ref(null)
const credentialsDialog = ref(false)
const credentialsModule = ref(null)
const credentialsForm = ref({})

onMounted(async () => {
  try {
    await store.fetchPaymentModules()
  }
  finally {
    isLoading.value = false
  }
})

const healthStatusColor = status => {
  const colors = { healthy: 'success', degraded: 'warning', down: 'error', unknown: 'secondary' }

  return colors[status] || 'secondary'
}

const healthStatusLabel = status => {
  const key = `payments.${status}`
  const translated = t(key)

  return translated !== key ? translated : status
}

const configStatusColor = status => {
  const colors = { active: 'success', misconfigured: 'warning', disabled: 'secondary' }

  return colors[status] || 'secondary'
}

const configStatusLabel = status => {
  const key = `payments.config_${status}`
  const translated = t(key)

  return translated !== key ? translated : status
}

const moduleIcon = module => {
  return module.icon_ref || 'tabler-credit-card'
}

const installModule = async module => {
  actionLoading.value = module.provider_key

  try {
    await store.installPaymentModule(module.provider_key)
    toast(t('payments.moduleInstalled'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToInstall'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const activateModule = async module => {
  if (!module.is_installed) {
    toast(t('payments.installFirst'), 'warning')

    return
  }

  actionLoading.value = module.provider_key

  try {
    await store.activatePaymentModule(module.provider_key)
    toast(t('payments.moduleActivated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToActivate'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const deactivateModule = async module => {
  actionLoading.value = module.provider_key

  try {
    await store.deactivatePaymentModule(module.provider_key)
    toast(t('payments.moduleDeactivated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToDeactivate'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const openCredentials = module => {
  credentialsModule.value = module
  credentialsForm.value = {}

  // Pre-fill with masked saved values, fallback to empty
  const masked = module.credentials_masked || {}
  if (module.credential_fields) {
    for (const field of module.credential_fields) {
      credentialsForm.value[field.key] = masked[field.key] || (field.key === 'mode' ? 'test' : '')
    }
  }

  credentialsDialog.value = true
}

const hasMode = computed(() => {
  return credentialsModule.value?.credential_fields?.some(f => f.key === 'mode')
})

const isFieldVisible = field => {
  if (!field.when_mode) return true

  return credentialsForm.value.mode === field.when_mode
}

const saveCredentials = async () => {
  actionLoading.value = credentialsModule.value.provider_key

  try {
    await store.updatePaymentModuleCredentials(credentialsModule.value.provider_key, credentialsForm.value)
    toast(t('payments.credentialsSaved'), 'success')
    credentialsDialog.value = false
    await store.fetchPaymentModules()
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToSaveCredentials'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const checkHealth = async module => {
  actionLoading.value = module.provider_key

  try {
    const data = await store.checkPaymentModuleHealth(module.provider_key)

    toast(`${t('payments.healthCheck')}: ${healthStatusLabel(data.health.status)}`, data.health.status === 'healthy' ? 'success' : 'warning')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.down'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}
</script>

<template>
  <div>
    <VSkeletonLoader
      v-if="isLoading"
      type="card, card"
    />

    <div
      v-else-if="store.paymentModules.length === 0"
      class="text-center pa-6 text-disabled"
    >
      {{ t('payments.noModules') }}
    </div>

    <div v-else>
      <VCard
        v-for="module in store.paymentModules"
        :key="module.provider_key"
        flat
        border
        class="mb-3"
        :style="module.is_active ? 'border-color: rgb(var(--v-theme-primary))' : ''"
      >
        <div class="d-flex align-center pa-4">
          <VAvatar
            size="40"
            variant="tonal"
            :color="module.is_active ? 'primary' : 'secondary'"
            class="me-4"
          >
            <VIcon :icon="moduleIcon(module)" />
          </VAvatar>

          <div class="flex-grow-1">
            <h6 class="text-h6">
              {{ module.name }}
            </h6>
            <p class="text-body-2 text-disabled mb-0">
              {{ module.description }}
            </p>
            <div
              v-if="module.supported_methods.length"
              class="d-flex gap-1 mt-1"
            >
              <VChip
                v-for="method in module.supported_methods"
                :key="method"
                size="x-small"
                variant="tonal"
                color="info"
              >
                {{ method }}
              </VChip>
            </div>
          </div>

          <div class="d-flex align-center gap-2">
            <!-- Stripe mode badge -->
            <VChip
              v-if="module.stripe_mode"
              size="small"
              variant="tonal"
              :color="module.stripe_mode === 'live' ? 'error' : 'info'"
            >
              {{ module.stripe_mode.toUpperCase() }}
            </VChip>

            <!-- Configuration status -->
            <VChip
              v-if="module.is_installed && module.configuration_status"
              :color="configStatusColor(module.configuration_status)"
              size="small"
              variant="tonal"
            >
              {{ configStatusLabel(module.configuration_status) }}
            </VChip>

            <!-- Health status -->
            <VChip
              v-if="module.is_installed"
              :color="healthStatusColor(module.health_status)"
              size="small"
              variant="tonal"
            >
              {{ healthStatusLabel(module.health_status) }}
            </VChip>

            <!-- Status chip -->
            <VChip
              :color="module.is_active ? 'success' : module.is_installed ? 'info' : 'secondary'"
              size="small"
              variant="tonal"
            >
              {{ module.is_active ? t('payments.statusActive') : module.is_installed ? t('payments.statusInstalled') : t('payments.statusNotInstalled') }}
            </VChip>
          </div>
        </div>

        <VCardActions class="px-4 pb-3">
          <template v-if="!module.is_installed">
            <VBtn
              size="small"
              variant="tonal"
              :loading="actionLoading === module.provider_key"
              @click="installModule(module)"
            >
              {{ t('payments.install') }}
            </VBtn>
          </template>

          <template v-else>
            <VBtn
              v-if="!module.is_active"
              size="small"
              color="success"
              variant="tonal"
              :loading="actionLoading === module.provider_key"
              @click="activateModule(module)"
            >
              {{ t('payments.activate') }}
            </VBtn>

            <VBtn
              v-else
              size="small"
              color="error"
              variant="tonal"
              :loading="actionLoading === module.provider_key"
              @click="deactivateModule(module)"
            >
              {{ t('payments.deactivate') }}
            </VBtn>

            <VBtn
              v-if="module.requires_credentials"
              size="small"
              variant="tonal"
              @click="openCredentials(module)"
            >
              {{ t('payments.credentials') }}
            </VBtn>

            <VBtn
              size="small"
              variant="tonal"
              :loading="actionLoading === module.provider_key"
              @click="checkHealth(module)"
            >
              {{ t('payments.checkHealth') }}
            </VBtn>
          </template>
        </VCardActions>
      </VCard>
    </div>

    <!-- Credentials Dialog -->
    <VDialog
      v-model="credentialsDialog"
      max-width="500"
    >
      <VCard>
        <VCardTitle class="pt-5 px-6">
          {{ t('payments.credentials') }} — {{ credentialsModule?.name }}
        </VCardTitle>

        <VCardText class="px-6">
          <template v-if="credentialsModule?.credential_fields?.length">
            <!-- Mode toggle (if module has a mode field) -->
            <div
              v-if="hasMode"
              class="mb-5"
            >
              <VSwitch
                v-model="credentialsForm.mode"
                :label="credentialsForm.mode === 'live' ? t('payments.modeLive') : t('payments.modeTest')"
                true-value="live"
                false-value="test"
                :color="credentialsForm.mode === 'live' ? 'error' : 'info'"
                inset
              />

              <VAlert
                v-if="credentialsForm.mode === 'live'"
                type="warning"
                variant="tonal"
                density="compact"
                icon="tabler-alert-triangle"
                class="mt-3"
              >
                {{ t('payments.liveWarning') }}
              </VAlert>
            </div>

            <!-- Credential fields (filtered by mode) -->
            <div
              v-for="field in credentialsModule.credential_fields"
              :key="field.key"
              class="mb-3"
            >
              <template v-if="field.type !== 'select' && isFieldVisible(field)">
                <AppTextField
                  v-model="credentialsForm[field.key]"
                  :label="field.label"
                  :type="field.type === 'password' ? 'password' : 'text'"
                  :placeholder="credentialsModule.has_credentials ? '••••••••' : ''"
                  :hint="field.hint || ''"
                  persistent-hint
                />
              </template>
            </div>
          </template>
        </VCardText>

        <VCardActions class="px-6 pb-5">
          <VSpacer />
          <VBtn
            color="secondary"
            variant="tonal"
            @click="credentialsDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="actionLoading === credentialsModule?.provider_key"
            @click="saveCredentials"
          >
            {{ t('payments.saveCredentials') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
