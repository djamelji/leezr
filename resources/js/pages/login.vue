<script setup>
const { t } = useI18n()

import { useAuthStore } from '@/core/stores/auth'
import { useAppName } from '@/composables/useAppName'
import { safeRedirect } from '@/utils/safeRedirect'
import { checkVersionOnMount } from '@/utils/versionCheck'
import { useGenerateImageVariant } from '@core/composable/useGenerateImageVariant'
import authV2LoginIllustrationBorderedDark from '@images/pages/auth-v2-login-illustration-bordered-dark.png'
import authV2LoginIllustrationBorderedLight from '@images/pages/auth-v2-login-illustration-bordered-light.png'
import authV2LoginIllustrationDark from '@images/pages/auth-v2-login-illustration-dark.png'
import authV2LoginIllustrationLight from '@images/pages/auth-v2-login-illustration-light.png'
import authV2MaskDark from '@images/pages/misc-mask-dark.png'
import authV2MaskLight from '@images/pages/misc-mask-light.png'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

definePage({
  meta: {
    layout: 'blank',
    public: true,
    unauthenticatedOnly: true,
  },
})

usePublicTheme()
checkVersionOnMount()

const appName = useAppName()
const auth = useAuthStore()
const route = useRoute()

const form = ref({
  email: '',
  password: '',
})

const isPasswordVisible = ref(false)
const isLoading = ref(false)
const errorMessage = ref('')
const requires2fa = ref(false)
const otpCode = ref('')

const handleLogin = async () => {
  if (isLoading.value) return
  isLoading.value = true
  errorMessage.value = ''

  try {
    const data = await auth.login({
      email: form.value.email,
      password: form.value.password,
    })

    // ADR-351: If 2FA is required, show OTP input
    if (data?.requires_2fa) {
      requires2fa.value = true
      isLoading.value = false

      return
    }
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('auth.invalidCredentials')
    isLoading.value = false

    return
  }

  // Login réussi — reload complet (le boot runtime se fera au chargement frais)
  window.location.href = safeRedirect(route.query.redirect, '/dashboard')
}

const handleVerify2fa = async () => {
  if (isLoading.value) return
  isLoading.value = true
  errorMessage.value = ''

  try {
    await auth.verify2fa(otpCode.value)
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('auth.invalidCode')
    isLoading.value = false

    return
  }

  window.location.href = safeRedirect(route.query.redirect, '/dashboard')
}

const authThemeImg = useGenerateImageVariant(authV2LoginIllustrationLight, authV2LoginIllustrationDark, authV2LoginIllustrationBorderedLight, authV2LoginIllustrationBorderedDark, true)
const authThemeMask = useGenerateImageVariant(authV2MaskLight, authV2MaskDark)
</script>

<template>
  <RouterLink to="/">
    <div class="auth-logo d-flex align-center gap-x-3">
      <VNodeRenderer :nodes="themeConfig.app.logo" />
    </div>
  </RouterLink>

  <VRow
    no-gutters
    class="auth-wrapper bg-surface"
  >
    <VCol
      md="8"
      class="d-none d-md-flex"
    >
      <div class="position-relative bg-background w-100 me-0">
        <div
          class="d-flex align-center justify-center w-100 h-100"
          style="padding-inline: 6.25rem;"
        >
          <VImg
            max-width="613"
            :src="authThemeImg"
            class="auth-illustration mt-16 mb-2"
          />
        </div>

        <img
          class="auth-footer-mask flip-in-rtl"
          :src="authThemeMask"
          alt="auth-footer-mask"
          height="280"
          width="100"
        >
      </div>
    </VCol>

    <VCol
      cols="12"
      md="4"
      class="auth-card-v2 d-flex align-center justify-center"
    >
      <VCard
        flat
        :max-width="500"
        class="mt-12 mt-sm-0 pa-6"
      >
        <VCardText>
          <h4 class="text-h4 mb-1">
            {{ requires2fa ? t('auth.twoStepVerification') : t('auth.welcomeTo', { app: appName }) }}
          </h4>
          <p class="mb-0">
            {{ requires2fa ? t('auth.enter2faCode') : t('auth.signInSubtitle') }}
          </p>
        </VCardText>
        <VCardText>
          <VAlert
            v-if="errorMessage"
            type="error"
            class="mb-6"
            closable
            @click:close="errorMessage = ''"
          >
            {{ errorMessage }}
          </VAlert>

          <!-- Login form -->
          <VForm
            v-if="!requires2fa"
            @submit.prevent="handleLogin"
          >
            <VRow>
              <VCol cols="12">
                <AppTextField
                  v-model="form.email"
                  autofocus
                  :label="t('auth.email')"
                  type="email"
                  placeholder="johndoe@email.com"
                />
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="form.password"
                  :label="t('auth.password')"
                  placeholder="············"
                  :type="isPasswordVisible ? 'text' : 'password'"
                  autocomplete="current-password"
                  :append-inner-icon="isPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="isPasswordVisible = !isPasswordVisible"
                />

                <div class="d-flex align-center justify-end my-6">
                  <RouterLink
                    class="text-primary text-body-2"
                    to="/forgot-password"
                  >
                    {{ t('auth.forgotPassword') }}
                  </RouterLink>
                </div>

                <VBtn
                  block
                  type="submit"
                  :loading="isLoading"
                >
                  {{ t('auth.login') }}
                </VBtn>
              </VCol>

              <VCol
                cols="12"
                class="text-body-1 text-center"
              >
                <span class="d-inline-block">
                  {{ t('auth.newOnPlatform') }}
                </span>
                <RouterLink
                  class="text-primary ms-1 d-inline-block text-body-1"
                  to="/register"
                >
                  {{ t('auth.createAccount') }}
                </RouterLink>
              </VCol>
            </VRow>
          </VForm>

          <!-- 2FA verification form -->
          <VForm
            v-else
            @submit.prevent="handleVerify2fa"
          >
            <VRow>
              <VCol cols="12">
                <p class="text-body-1 mb-2">
                  {{ t('auth.enter6DigitCode') }}
                </p>
                <VOtpInput
                  v-model="otpCode"
                  type="number"
                  class="pa-0"
                  @finish="handleVerify2fa"
                />
              </VCol>

              <VCol cols="12">
                <VBtn
                  block
                  type="submit"
                  :loading="isLoading"
                >
                  {{ t('auth.verifyCode') }}
                </VBtn>
              </VCol>

              <VCol
                cols="12"
                class="text-center"
              >
                <a
                  href="#"
                  class="text-primary text-body-2"
                  @click.prevent="requires2fa = false; otpCode = ''; errorMessage = ''"
                >
                  {{ t('auth.backToLogin') }}
                </a>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth";
</style>
