<script setup>
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const settingsStore = usePlatformSettingsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)

const defaults = {
  idle_timeout: 120,
  warning_threshold: 5,
  heartbeat_interval: 10,
  remember_me_enabled: false,
  remember_me_duration: 43200,
}

const form = reactive({ ...defaults })

const loadSettings = data => {
  Object.assign(form, data)
}

onMounted(async () => {
  try {
    await settingsStore.fetchSessionSettings()
    if (settingsStore.sessionSettings)
      loadSettings(settingsStore.sessionSettings)
  }
  finally {
    isLoading.value = false
  }
})

const warningError = computed(() => {
  if (form.warning_threshold >= form.idle_timeout)
    return t('platformSettings.session.mustBeLessThanTimeout')

  return null
})

const heartbeatError = computed(() => {
  if (form.heartbeat_interval >= form.idle_timeout)
    return t('platformSettings.session.mustBeLessThanTimeout')

  return null
})

const hasErrors = computed(() => !!warningError.value || !!heartbeatError.value)

const save = async () => {
  if (hasErrors.value)
    return

  isSaving.value = true
  try {
    const data = await settingsStore.updateSessionSettings({ ...form })

    toast(data.message, 'success')
    loadSettings(data.session)
  }
  catch (error) {
    toast(error?.data?.message || t('platformSettings.session.failedToSave'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

const resetToDefaults = async () => {
  isSaving.value = true
  try {
    const data = await settingsStore.updateSessionSettings({ ...defaults })

    toast(t('platformSettings.session.resetSuccess'), 'success')
    loadSettings(data.session)
  }
  catch (error) {
    toast(error?.data?.message || t('platformSettings.session.failedToSave'), 'error')
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div>
    <VCard :loading="isLoading">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-clock-shield"
          class="me-2"
        />
        {{ t('platformSettings.session.title') }}
      </VCardTitle>
      <VCardSubtitle>
        {{ t('platformSettings.session.subtitle') }}
      </VCardSubtitle>

      <VCardText v-if="!isLoading">
        <!-- Timeout Settings -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.session.timeoutSettings') }}
        </h6>

        <VRow class="mb-6">
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model.number="form.idle_timeout"
              :label="t('platformSettings.session.idleTimeout')"
              type="number"
              min="5"
              max="1440"
              :hint="t('platformSettings.session.idleTimeoutHint')"
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model.number="form.warning_threshold"
              :label="t('platformSettings.session.warningThreshold')"
              type="number"
              min="1"
              max="30"
              :error-messages="warningError"
              :hint="t('platformSettings.session.warningThresholdHint')"
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model.number="form.heartbeat_interval"
              :label="t('platformSettings.session.heartbeatInterval')"
              type="number"
              min="1"
              max="60"
              :error-messages="heartbeatError"
              :hint="t('platformSettings.session.heartbeatIntervalHint')"
              persistent-hint
            />
          </VCol>
        </VRow>

        <VDivider class="mb-6" />

        <!-- Remember Me -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.session.rememberMe') }}
        </h6>

        <div class="d-flex flex-column gap-4 mb-4">
          <div class="d-flex align-center justify-space-between">
            <VLabel for="remember-me">
              {{ t('platformSettings.session.enableRememberMe') }}
            </VLabel>
            <VSwitch
              id="remember-me"
              v-model="form.remember_me_enabled"
              disabled
              hide-details
            />
          </div>

          <AppTextField
            v-model.number="form.remember_me_duration"
            :label="t('platformSettings.session.rememberMeDuration')"
            type="number"
            disabled
            :hint="t('platformSettings.session.rememberMeDurationHint')"
            persistent-hint
          />
        </div>

        <VAlert
          type="info"
          variant="tonal"
        >
          {{ t('platformSettings.session.rememberMeNotice') }}
        </VAlert>
      </VCardText>

      <VDivider />

      <VCardActions class="pa-4">
        <VBtn
          color="primary"
          :loading="isSaving"
          :disabled="isLoading || hasErrors"
          @click="save"
        >
          {{ t('common.save') }}
        </VBtn>
        <VBtn
          variant="outlined"
          :loading="isSaving"
          :disabled="isLoading"
          @click="resetToDefaults"
        >
          {{ t('common.reset') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
