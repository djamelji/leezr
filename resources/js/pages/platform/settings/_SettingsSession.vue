<script setup>
import { usePlatformStore } from '@/core/stores/platform'
import { useAppToast } from '@/composables/useAppToast'

const platformStore = usePlatformStore()
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
    await platformStore.fetchSessionSettings()
    if (platformStore.sessionSettings)
      loadSettings(platformStore.sessionSettings)
  }
  finally {
    isLoading.value = false
  }
})

const warningError = computed(() => {
  if (form.warning_threshold >= form.idle_timeout)
    return 'Must be less than idle timeout.'

  return null
})

const heartbeatError = computed(() => {
  if (form.heartbeat_interval >= form.idle_timeout)
    return 'Must be less than idle timeout.'

  return null
})

const hasErrors = computed(() => !!warningError.value || !!heartbeatError.value)

const save = async () => {
  if (hasErrors.value)
    return

  isSaving.value = true
  try {
    const data = await platformStore.updateSessionSettings({ ...form })

    toast(data.message, 'success')
    loadSettings(data.session)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to save session settings.', 'error')
  }
  finally {
    isSaving.value = false
  }
}

const resetToDefaults = async () => {
  isSaving.value = true
  try {
    const data = await platformStore.updateSessionSettings({ ...defaults })

    toast('Session settings reset to defaults.', 'success')
    loadSettings(data.session)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to reset session settings.', 'error')
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
        Session Governance
      </VCardTitle>
      <VCardSubtitle>
        Configure session timeout, keepalive, and warning behavior for all users.
      </VCardSubtitle>

      <VCardText v-if="!isLoading">
        <!-- Timeout Settings -->
        <h6 class="text-h6 mb-4">
          Timeout Settings
        </h6>

        <VRow class="mb-6">
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model.number="form.idle_timeout"
              label="Idle Timeout (minutes)"
              type="number"
              min="5"
              max="1440"
              hint="Session expires after this many minutes of inactivity. Min: 5, Max: 1440 (24h)."
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model.number="form.warning_threshold"
              label="Warning Threshold (minutes)"
              type="number"
              min="1"
              max="30"
              :error-messages="warningError"
              hint="Show expiration warning this many minutes before timeout."
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model.number="form.heartbeat_interval"
              label="Heartbeat Interval (minutes)"
              type="number"
              min="1"
              max="60"
              :error-messages="heartbeatError"
              hint="How often the client checks session status with the server."
              persistent-hint
            />
          </VCol>
        </VRow>

        <VDivider class="mb-6" />

        <!-- Remember Me -->
        <h6 class="text-h6 mb-4">
          Remember Me
        </h6>

        <div class="d-flex flex-column gap-4 mb-4">
          <div class="d-flex align-center justify-space-between">
            <VLabel for="remember-me">
              Enable "Remember Me" on login
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
            label="Remember Me Duration (minutes)"
            type="number"
            disabled
            hint="How long the 'Remember Me' token stays valid."
            persistent-hint
          />
        </div>

        <VAlert
          type="info"
          variant="tonal"
        >
          Remember Me will be available with the upcoming authentication upgrade (Passport/Sanctum).
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
          Save
        </VBtn>
        <VBtn
          variant="outlined"
          :loading="isSaving"
          :disabled="isLoading"
          @click="resetToDefaults"
        >
          Reset to Defaults
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
