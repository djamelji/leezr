<script setup>
import AppQrCode from '@/core/components/AppQrCode.vue'
import { $platformApi } from '@/utils/platformApi'

const { t } = useI18n()

// ── Password change ──────────────────────────────────────
const form = ref({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const isCurrentPasswordVisible = ref(false)
const isNewPasswordVisible = ref(false)
const isConfirmPasswordVisible = ref(false)
const isLoading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const handleChangePassword = async () => {
  isLoading.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    await $platformApi('/me/password', {
      method: 'PUT',
      body: {
        current_password: form.value.current_password,
        password: form.value.password,
        password_confirmation: form.value.password_confirmation,
      },
    })

    successMessage.value = t('accountSettings.passwordUpdated')
    form.value = {
      current_password: '',
      password: '',
      password_confirmation: '',
    }
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('accountSettings.failedToUpdatePassword')
  }
  finally {
    isLoading.value = false
  }
}

// ── 2FA (ADR-351) ────────────────────────────────────────
const twoFactorEnabled = ref(false)
const twoFactorLoading = ref(false)
const showSetupDialog = ref(false)
const showDisableDialog = ref(false)
const showBackupCodesDialog = ref(false)

const setupData = ref({ secret: '', qr_url: '', backup_codes: [] })
const setupCode = ref('')
const setupError = ref('')
const disablePassword = ref('')
const disableCode = ref('')
const disableError = ref('')
const backupCodes = ref([])

// ADR-838 (Leezr 4.2 M2) — regenerating backup codes requires a fresh factor
const showRegenerateDialog = ref(false)
const regenerateCode = ref('')
const regenerateError = ref('')

// Server messages are already localized; a 429 carries the wait time
const securityError = e => e?.data?.errors?.code?.[0] || e?.data?.errors?.password?.[0] || e?.data?.message || t('common.error')

const closeBackupCodes = () => {
  showBackupCodesDialog.value = false
  backupCodes.value = [] // shown once: never kept in memory after closing
}

onMounted(async () => {
  try {
    const data = await $platformApi('/2fa/status')
    twoFactorEnabled.value = data.enabled
  }
  catch {
    // ignore
  }
})

const startSetup = async () => {
  twoFactorLoading.value = true
  setupError.value = ''
  setupCode.value = ''

  try {
    const data = await $platformApi('/2fa/enable', { method: 'POST' })
    setupData.value = data
    showSetupDialog.value = true
  }
  catch (e) {
    errorMessage.value = e?.data?.message || t('common.error')
  }
  finally {
    twoFactorLoading.value = false
  }
}

const confirmSetup = async () => {
  twoFactorLoading.value = true
  setupError.value = ''

  try {
    await $platformApi('/2fa/confirm', {
      method: 'POST',
      body: { code: setupCode.value },
    })

    twoFactorEnabled.value = true
    showSetupDialog.value = false
    successMessage.value = t('twoFactor.enabledSuccess')

    // ADR-838 — the initial backup codes are shown once, right after activation
    backupCodes.value = setupData.value.backup_codes || []
    setupData.value.secret = ''
    setupData.value.backup_codes = [] // eslint-disable-line camelcase -- API field name

    if (backupCodes.value.length) showBackupCodesDialog.value = true
  }
  catch (e) {
    setupError.value = e?.data?.message || t('auth.invalidCode')
  }
  finally {
    twoFactorLoading.value = false
  }
}

const disableTwoFactor = async () => {
  twoFactorLoading.value = true
  disableError.value = ''

  try {
    await $platformApi('/2fa', {
      method: 'DELETE',
      body: { password: disablePassword.value, code: disableCode.value.trim() },
    })

    twoFactorEnabled.value = false
    showDisableDialog.value = false
    disablePassword.value = ''
    disableCode.value = ''
    successMessage.value = t('twoFactor.disabledSuccess')
  }
  catch (e) {
    disableError.value = securityError(e)
    disableCode.value = ''
  }
  finally {
    twoFactorLoading.value = false
  }
}

const openRegenerate = () => {
  regenerateCode.value = ''
  regenerateError.value = ''
  showRegenerateDialog.value = true
}

const regenerateBackupCodes = async () => {
  twoFactorLoading.value = true
  regenerateError.value = ''

  try {
    const data = await $platformApi('/2fa/backup-codes', { method: 'POST', body: { code: regenerateCode.value.trim() } })
    showRegenerateDialog.value = false
    regenerateCode.value = ''

    backupCodes.value = data.backup_codes
    showBackupCodesDialog.value = true
  }
  catch (e) {
    regenerateError.value = securityError(e)
    regenerateCode.value = ''
  }
  finally {
    twoFactorLoading.value = false
  }
}
</script>

<template>
  <VRow>
    <!-- Password Change -->
    <VCol cols="12">
      <VCard :title="t('accountSettings.changePassword')">
        <VCardText class="pt-0">
          <VAlert
            v-if="successMessage"
            type="success"
            class="mb-4"
            closable
            @click:close="successMessage = ''"
          >
            {{ successMessage }}
          </VAlert>

          <VAlert
            v-if="errorMessage"
            type="error"
            class="mb-4"
            closable
            @click:close="errorMessage = ''"
          >
            {{ errorMessage }}
          </VAlert>

          <VForm @submit.prevent="handleChangePassword">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.current_password"
                  :label="t('accountSettings.currentPassword')"
                  placeholder="············"
                  :type="isCurrentPasswordVisible ? 'text' : 'password'"
                  autocomplete="current-password"
                  :append-inner-icon="isCurrentPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="isCurrentPasswordVisible = !isCurrentPasswordVisible"
                />
              </VCol>
            </VRow>

            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.password"
                  :label="t('accountSettings.newPassword')"
                  placeholder="············"
                  :type="isNewPasswordVisible ? 'text' : 'password'"
                  autocomplete="new-password"
                  :append-inner-icon="isNewPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="isNewPasswordVisible = !isNewPasswordVisible"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.password_confirmation"
                  :label="t('accountSettings.confirmNewPassword')"
                  placeholder="············"
                  :type="isConfirmPasswordVisible ? 'text' : 'password'"
                  autocomplete="new-password"
                  :append-inner-icon="isConfirmPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="isConfirmPasswordVisible = !isConfirmPasswordVisible"
                />
              </VCol>

              <VCol cols="12">
                <p class="text-body-2">
                  {{ t('accountSettings.passwordRequirements') }}
                </p>
                <ul class="ps-6 mb-6">
                  <!-- ADR-838 : the single password policy (PasswordPolicy) now applies here too -->
                  <li class="text-body-2 mb-1">
                    {{ t('accountSettings.minCharsLong') }}
                  </li>
                  <li class="text-body-2 mb-1">
                    {{ t('accountSettings.requireUppercase') }}
                  </li>
                  <li class="text-body-2 mb-1">
                    {{ t('accountSettings.requireLowercase') }}
                  </li>
                  <li class="text-body-2 mb-1">
                    {{ t('accountSettings.requireNumber') }}
                  </li>
                  <li class="text-body-2 mb-1">
                    {{ t('accountSettings.requireSymbol') }}
                  </li>
                </ul>

                <VBtn
                  type="submit"
                  :loading="isLoading"
                >
                  {{ t('common.saveChanges') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Two-Factor Authentication (ADR-351) -->
    <VCol cols="12">
      <VCard>
        <VCardTitle class="d-flex align-center">
          <VIcon
            icon="tabler-shield-lock"
            class="me-2"
          />
          {{ t('twoFactor.title') }}
        </VCardTitle>
        <VCardSubtitle>{{ t('twoFactor.description') }}</VCardSubtitle>
        <VCardText>
          <VAlert
            v-if="!twoFactorEnabled"
            type="warning"
            class="mb-4"
          >
            {{ t('twoFactor.requiredWarning') }}
          </VAlert>

          <div v-if="twoFactorEnabled">
            <div class="d-flex align-center gap-2 mb-4">
              <VChip
                color="success"
                size="small"
              >
                <VIcon
                  start
                  icon="tabler-check"
                  size="14"
                />
                {{ t('twoFactor.active') }}
              </VChip>
            </div>

            <div class="d-flex gap-2">
              <VBtn
                variant="outlined"
                data-testid="two-factor-regenerate"
                @click="openRegenerate"
                :loading="twoFactorLoading"
              >
                {{ t('twoFactor.regenerateBackupCodes') }}
              </VBtn>
              <VBtn
                color="error"
                variant="outlined"
                data-testid="two-factor-disable"
                @click="showDisableDialog = true"
              >
                {{ t('twoFactor.disable') }}
              </VBtn>
            </div>
          </div>

          <div v-else>
            <VBtn
              color="primary"
              :loading="twoFactorLoading"
              @click="startSetup"
            >
              <VIcon
                start
                icon="tabler-shield-lock"
              />
              {{ t('twoFactor.enable') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>

  <!-- Setup 2FA Dialog -->
  <VDialog
    v-model="showSetupDialog"
    max-width="600"
    persistent
  >
    <VCard :title="t('twoFactor.setupTitle')">
      <VCardText>
        <p class="text-body-1 mb-4">
          {{ t('twoFactor.scanQrCode') }}
        </p>

        <div class="text-center mb-4">
          <AppQrCode
            v-if="setupData.qr_url"
            :value="setupData.qr_url"
            :size="200"
          />
        </div>

        <VAlert
          variant="tonal"
          color="warning"
          class="mb-4"
        >
          <template #title>
            {{ t('twoFactor.manualEntry') }}
          </template>
          <code class="text-body-1">{{ setupData.secret }}</code>
        </VAlert>

        <VAlert
          v-if="setupError"
          type="error"
          class="mb-4"
        >
          {{ setupError }}
        </VAlert>

        <AppTextField
          v-model="setupCode"
          :label="t('twoFactor.verificationCode')"
          placeholder="123456"
          maxlength="6"
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          variant="outlined"
          @click="showSetupDialog = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="twoFactorLoading"
          :disabled="setupCode.length !== 6"
          @click="confirmSetup"
        >
          {{ t('twoFactor.verify') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Disable 2FA Dialog -->
  <VDialog
    v-model="showDisableDialog"
    max-width="450"
  >
    <VCard :title="t('twoFactor.disableTitle')">
      <VCardText>
        <p class="text-body-1 mb-4">
          {{ t('twoFactor.disableWarning') }}
        </p>

        <VAlert
          v-if="disableError"
          type="error"
          class="mb-4"
          data-testid="two-factor-disable-error"
        >
          {{ disableError }}
        </VAlert>

        <AppTextField
          v-model="disablePassword"
          :label="t('accountSettings.currentPassword')"
          placeholder="············"
          type="password"
          autocomplete="current-password"
          class="mb-4"
          data-testid="two-factor-disable-password"
        />

        <AppTextField
          v-model="disableCode"
          :label="t('twoFactor.factorCode')"
          :hint="t('twoFactor.factorCodeHint')"
          persistent-hint
          autocomplete="one-time-code"
          maxlength="32"
          data-testid="two-factor-disable-code"
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          variant="outlined"
          @click="showDisableDialog = false; disablePassword = ''; disableCode = ''; disableError = ''"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="error"
          :loading="twoFactorLoading"
          :disabled="!disablePassword || !disableCode.trim()"
          data-testid="two-factor-disable-confirm"
          @click="disableTwoFactor"
        >
          {{ t('twoFactor.disable') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ADR-838 — Regenerate backup codes: confirm with the active factor -->
  <VDialog
    v-model="showRegenerateDialog"
    max-width="450"
  >
    <VCard :title="t('twoFactor.regenerateTitle')">
      <VCardText>
        <p class="text-body-1 mb-4">
          {{ t('twoFactor.regenerateWarning') }}
        </p>

        <VAlert
          v-if="regenerateError"
          type="error"
          class="mb-4"
          data-testid="two-factor-regenerate-error"
        >
          {{ regenerateError }}
        </VAlert>

        <AppTextField
          v-model="regenerateCode"
          :label="t('twoFactor.factorCode')"
          :hint="t('twoFactor.factorCodeHint')"
          persistent-hint
          autocomplete="one-time-code"
          maxlength="32"
          data-testid="two-factor-regenerate-code"
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          variant="outlined"
          @click="showRegenerateDialog = false; regenerateCode = ''; regenerateError = ''"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="twoFactorLoading"
          :disabled="!regenerateCode.trim()"
          data-testid="two-factor-regenerate-confirm"
          @click="regenerateBackupCodes"
        >
          {{ t('twoFactor.regenerateConfirm') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Backup Codes Dialog (shown once, ADR-838) -->
  <VDialog
    v-model="showBackupCodesDialog"
    max-width="450"
    persistent
  >
    <VCard :title="t('twoFactor.backupCodesTitle')">
      <VCardText>
        <p class="text-body-1 mb-4">
          {{ t('twoFactor.backupCodesDesc') }}
        </p>

        <div class="d-flex flex-wrap gap-2 mb-4">
          <VChip
            v-for="code in backupCodes"
            :key="code"
            variant="outlined"
            data-testid="two-factor-backup-code"
            class="font-weight-bold"
          >
            {{ code }}
          </VChip>
        </div>

        <VAlert
          type="warning"
          variant="tonal"
        >
          {{ t('twoFactor.backupCodesWarning') }}
        </VAlert>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          color="primary"
          data-testid="two-factor-codes-close"
          @click="closeBackupCodes"
        >
          {{ t('common.close') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
