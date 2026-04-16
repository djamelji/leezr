<script setup>
import { $platformApi } from '@/utils/platformApi'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const { toast } = useAppToast()
const { t } = useI18n()

// ── SMTP (platform-wide) ──
const smtpForm = ref({
  smtp_host: '',
  smtp_port: 587,
  smtp_encryption: 'tls',
  smtp_username: '',
  smtp_password: '',
})

const originalSmtp = ref({})
const smtpFormRef = ref()

// ── Identity (per-admin) ──
const identityForm = ref({
  from_name: '',
  from_email: '',
  reply_to: '',
})

const originalIdentity = ref({})
const identityFormRef = ref()

// ── Read-only branding (from theme + general) ──
const branding = ref({ app_name: 'Leezr', primary_color: '#7367F0' })

// ── Loading states ──
const isLoading = ref(true)
const isSavingSmtp = ref(false)
const isSavingIdentity = ref(false)
const isTesting = ref(false)
const testResult = ref(null)
const passwordSet = ref(false)

const encryptionOptions = [
  { title: 'TLS (587)', value: 'tls' },
  { title: 'SSL (465)', value: 'ssl' },
  { title: 'None', value: 'none' },
]

const { isDirty: isSmtpDirty } = useUnsavedChanges(smtpForm, originalSmtp)
const { isDirty: isIdentityDirty } = useUnsavedChanges(identityForm, originalIdentity)

const fetchAll = async () => {
  isLoading.value = true
  try {
    const [settingsData, identityData] = await Promise.all([
      $platformApi('/email/settings'),
      $platformApi('/email/identity'),
    ])

    const s = settingsData.settings || {}

    smtpForm.value = {
      smtp_host: s.smtp_host || '',
      smtp_port: s.smtp_port || 587,
      smtp_encryption: s.smtp_encryption || 'tls',
      smtp_username: s.smtp_username || '',
      smtp_password: '',
    }
    passwordSet.value = s.smtp_password_set || false
    originalSmtp.value = { ...smtpForm.value }

    branding.value = settingsData.branding || { app_name: 'Leezr', primary_color: '#7367F0' }

    const id = identityData.identity || {}

    identityForm.value = {
      from_name: id.from_name || '',
      from_email: id.from_email || '',
      reply_to: id.reply_to || '',
    }
    originalIdentity.value = { ...identityForm.value }
  }
  catch (e) {
    toast(t('email.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

const saveSmtp = async () => {
  const { valid } = await smtpFormRef.value.validate()
  if (!valid) return

  isSavingSmtp.value = true
  try {
    const payload = { ...smtpForm.value }

    // Don't send empty password if one is already set
    if (!payload.smtp_password && passwordSet.value) {
      payload.smtp_password = '********'
    }

    await $platformApi('/email/settings', { method: 'PUT', body: payload })
    toast(t('email.settingsSaved'), 'success')
    originalSmtp.value = { ...smtpForm.value }

    if (payload.smtp_password && payload.smtp_password !== '********') {
      passwordSet.value = true
    }
  }
  catch (e) {
    toast(e.message || t('email.saveError'), 'error')
  }
  finally {
    isSavingSmtp.value = false
  }
}

const saveIdentity = async () => {
  const { valid } = await identityFormRef.value.validate()
  if (!valid) return

  isSavingIdentity.value = true
  try {
    await $platformApi('/email/identity', { method: 'PUT', body: identityForm.value })
    toast(t('email.identitySaved'), 'success')
    originalIdentity.value = { ...identityForm.value }
  }
  catch (e) {
    toast(e.message || t('email.saveError'), 'error')
  }
  finally {
    isSavingIdentity.value = false
  }
}

const testConnection = async () => {
  isTesting.value = true
  testResult.value = null
  try {
    const data = await $platformApi('/email/settings/test', { method: 'POST' })

    testResult.value = data
    if (data.success) {
      toast(t('email.testSuccess'), 'success')
    }
    else {
      toast(t('email.testFailed', { error: data.message }), 'error')
    }
  }
  catch (e) {
    testResult.value = { success: false, message: e.message }
    toast(t('email.testFailed', { error: e.message }), 'error')
  }
  finally {
    isTesting.value = false
  }
}

onMounted(fetchAll)
</script>

<template>
  <VSkeletonLoader
    v-if="isLoading"
    type="card, card, card"
  />

  <template v-else>
    <VRow>
      <!-- SMTP Configuration (platform-wide) -->
      <VCol
        cols="12"
        md="6"
      >
        <VForm
          ref="smtpFormRef"
          @submit.prevent="saveSmtp"
        >
          <VCard>
            <VCardTitle class="d-flex align-center gap-2 pa-5">
              <VIcon
                icon="tabler-server"
                size="22"
              />
              {{ t('email.smtpConfig') }}
            </VCardTitle>
            <VCardText>
              <VRow>
                <VCol cols="12">
                  <AppTextField
                    v-model="smtpForm.smtp_host"
                    :label="t('email.smtpHost')"
                    :hint="t('email.smtpHostHelp')"
                    persistent-hint
                    placeholder="mail.leezr.com"
                  />
                </VCol>
                <VCol cols="6">
                  <AppTextField
                    v-model.number="smtpForm.smtp_port"
                    :label="t('email.smtpPort')"
                    :hint="t('email.smtpPortHelp')"
                    persistent-hint
                    type="number"
                    :rules="[v => !v || (v >= 1 && v <= 65535) || 'Port 1-65535']"
                  />
                </VCol>
                <VCol cols="6">
                  <AppSelect
                    v-model="smtpForm.smtp_encryption"
                    :label="t('email.smtpEncryption')"
                    :items="encryptionOptions"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="smtpForm.smtp_username"
                    :label="t('email.smtpUsername')"
                    placeholder="admin@leezr.com"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="smtpForm.smtp_password"
                    :label="t('email.smtpPassword')"
                    type="password"
                    :placeholder="passwordSet ? '••••••••' : ''"
                    :hint="passwordSet ? t('email.passwordAlreadySet') : ''"
                    persistent-hint
                  />
                </VCol>
                <VCol cols="12">
                  <div class="d-flex gap-3">
                    <VBtn
                      variant="outlined"
                      color="secondary"
                      :loading="isTesting"
                      :disabled="!smtpForm.smtp_host"
                      @click="testConnection"
                    >
                      <VIcon
                        icon="tabler-plug-connected"
                        class="me-2"
                      />
                      {{ t('email.testConnection') }}
                    </VBtn>
                    <VSpacer />
                    <VBtn
                      type="submit"
                      color="primary"
                      :loading="isSavingSmtp"
                      :disabled="isSavingSmtp"
                    >
                      <VIcon
                        icon="tabler-device-floppy"
                        class="me-2"
                      />
                      {{ t('common.save') }}
                    </VBtn>
                  </div>

                  <VAlert
                    v-if="testResult"
                    :type="testResult.success ? 'success' : 'error'"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                  >
                    {{ testResult.message }}
                  </VAlert>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VForm>
      </VCol>

      <!-- Right column: Identity + Branding preview -->
      <VCol
        cols="12"
        md="6"
      >
        <!-- Per-admin identity -->
        <VForm
          ref="identityFormRef"
          @submit.prevent="saveIdentity"
        >
          <VCard class="mb-6">
            <VCardTitle class="d-flex align-center gap-2 pa-5">
              <VIcon
                icon="tabler-user-circle"
                size="22"
              />
              {{ t('email.identity') }}
              <VChip
                size="x-small"
                color="info"
                variant="tonal"
                label
                class="ms-2"
              >
                {{ t('email.perAdmin') }}
              </VChip>
            </VCardTitle>
            <VCardText>
              <VAlert
                variant="tonal"
                type="info"
                density="compact"
                class="mb-4"
              >
                {{ t('email.identityHelp') }}
              </VAlert>
              <VRow>
                <VCol cols="12">
                  <AppTextField
                    v-model="identityForm.from_name"
                    :label="t('email.fromName')"
                    :rules="[requiredValidator]"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="identityForm.from_email"
                    :label="t('email.fromEmail')"
                    :rules="[requiredValidator, emailValidator]"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="identityForm.reply_to"
                    :label="t('email.replyTo')"
                    :rules="[v => !v || /.+@.+\..+/.test(v) || t('email.invalidEmail')]"
                  />
                </VCol>
                <VCol cols="12">
                  <div class="d-flex justify-end">
                    <VBtn
                      type="submit"
                      color="primary"
                      :loading="isSavingIdentity"
                      :disabled="isSavingIdentity"
                    >
                      <VIcon
                        icon="tabler-device-floppy"
                        class="me-2"
                      />
                      {{ t('common.save') }}
                    </VBtn>
                  </div>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VForm>

        <!-- Branding preview (read-only) -->
        <VCard>
          <VCardTitle class="d-flex align-center gap-2 pa-5">
            <VIcon
              icon="tabler-palette"
              size="22"
            />
            {{ t('email.branding') }}
            <VChip
              size="x-small"
              color="secondary"
              variant="tonal"
              label
              class="ms-2"
            >
              {{ t('email.readOnly') }}
            </VChip>
          </VCardTitle>
          <VCardText>
            <VAlert
              variant="tonal"
              type="info"
              density="compact"
              class="mb-4"
            >
              {{ t('email.brandingHelp') }}
            </VAlert>

            <!-- Logo preview -->
            <div class="mb-4">
              <label class="text-body-2 font-weight-medium d-block mb-2">
                {{ t('email.logoPreview') }}
              </label>
              <div
                class="pa-4 rounded border text-center"
                style="background: #f4f5fa;"
              >
                <span style="font-size: 22px; font-weight: 700; color: #333;">
                  {{ branding.app_name?.toLowerCase() }}
                </span>
                <span :style="{ fontSize: '22px', fontWeight: '700', color: branding.primary_color }">.</span>
              </div>
              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('email.logoEditHint') }}
              </div>
            </div>

            <!-- Color preview -->
            <div>
              <label class="text-body-2 font-weight-medium d-block mb-2">
                {{ t('email.colorPreview') }}
              </label>
              <div class="d-flex align-center gap-3">
                <div
                  :style="{
                    width: '36px',
                    height: '36px',
                    borderRadius: '6px',
                    backgroundColor: branding.primary_color,
                    border: '1px solid #ddd',
                  }"
                />
                <span class="text-body-1 font-weight-medium">{{ branding.primary_color }}</span>
                <span class="text-caption text-medium-emphasis">
                  {{ t('email.colorEditHint') }}
                </span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </template>
</template>
