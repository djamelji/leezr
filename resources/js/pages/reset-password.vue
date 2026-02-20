<script setup>
import { usePasswordStrength } from '@/composables/usePasswordStrength'
import { $api } from '@/utils/api'
import { refreshCsrf } from '@/utils/csrf'
import { useGenerateImageVariant } from '@core/composable/useGenerateImageVariant'
import authV2ResetPasswordIllustrationDark from '@images/pages/auth-v2-reset-password-illustration-dark.png'
import authV2ResetPasswordIllustrationLight from '@images/pages/auth-v2-reset-password-illustration-light.png'
import authV2MaskDark from '@images/pages/misc-mask-dark.png'
import authV2MaskLight from '@images/pages/misc-mask-light.png'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

usePublicTheme()

const route = useRoute()
const router = useRouter()

const form = ref({
  password: '',
  password_confirmation: '',
})

const isPasswordVisible = ref(false)
const isConfirmPasswordVisible = ref(false)
const isLoading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const { rules: passwordRules, strength: passwordStrength, color: strengthColor } = usePasswordStrength(computed(() => form.value.password))

const handleSubmit = async () => {
  isLoading.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    await refreshCsrf()

    const data = await $api('/reset-password', {
      method: 'POST',
      body: {
        token: route.query.token,
        email: route.query.email,
        password: form.value.password,
        password_confirmation: form.value.password_confirmation,
      },
    })

    successMessage.value = data.message

    setTimeout(() => {
      router.push('/login')
    }, 2000)
  }
  catch (error) {
    errorMessage.value = error?.data?.message || 'Failed to reset password. The link may have expired.'
  }
  finally {
    isLoading.value = false
  }
}

const authThemeImg = useGenerateImageVariant(authV2ResetPasswordIllustrationLight, authV2ResetPasswordIllustrationDark)
const authThemeMask = useGenerateImageVariant(authV2MaskLight, authV2MaskDark)
</script>

<template>
  <RouterLink to="/">
    <div class="auth-logo d-flex align-center gap-x-3">
      <VNodeRenderer :nodes="themeConfig.app.logo" />
      <h1 class="auth-title">
        {{ themeConfig.app.title }}
      </h1>
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
          style="padding-inline: 150px;"
        >
          <VImg
            max-width="451"
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
            Set Your Password
          </h4>
          <p class="mb-0">
            Your new password must be different from previously used passwords
          </p>
        </VCardText>

        <VCardText>
          <VAlert
            v-if="successMessage"
            type="success"
            class="mb-6"
          >
            {{ successMessage }} Redirecting to login...
          </VAlert>

          <VAlert
            v-if="errorMessage"
            type="error"
            class="mb-6"
            closable
            @click:close="errorMessage = ''"
          >
            {{ errorMessage }}
          </VAlert>

          <VForm @submit.prevent="handleSubmit">
            <VRow>
              <VCol cols="12">
                <AppTextField
                  v-model="form.password"
                  autofocus
                  label="New Password"
                  placeholder="············"
                  :type="isPasswordVisible ? 'text' : 'password'"
                  autocomplete="new-password"
                  :append-inner-icon="isPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="isPasswordVisible = !isPasswordVisible"
                />

                <VProgressLinear
                  :model-value="(passwordStrength / 5) * 100"
                  :color="strengthColor"
                  class="mt-2"
                  rounded
                  height="4"
                />

                <VList
                  density="compact"
                  class="pa-0 mt-1"
                >
                  <VListItem
                    v-for="rule in passwordRules"
                    :key="rule.label"
                    class="px-0"
                    style="min-height: 24px;"
                  >
                    <template #prepend>
                      <VIcon
                        :icon="rule.passed ? 'tabler-check' : 'tabler-x'"
                        :color="rule.passed ? 'success' : 'error'"
                        size="16"
                        class="me-2"
                      />
                    </template>
                    <VListItemTitle class="text-body-2">
                      {{ rule.label }}
                    </VListItemTitle>
                  </VListItem>
                </VList>
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="form.password_confirmation"
                  label="Confirm Password"
                  autocomplete="new-password"
                  placeholder="············"
                  :type="isConfirmPasswordVisible ? 'text' : 'password'"
                  :append-inner-icon="isConfirmPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="isConfirmPasswordVisible = !isConfirmPasswordVisible"
                />
              </VCol>

              <VCol cols="12">
                <VBtn
                  block
                  type="submit"
                  :loading="isLoading"
                >
                  Set New Password
                </VBtn>
              </VCol>

              <VCol cols="12">
                <RouterLink
                  class="d-flex align-center justify-center"
                  to="/login"
                >
                  <VIcon
                    icon="tabler-chevron-left"
                    size="20"
                    class="me-1 flip-in-rtl"
                  />
                  <span>Back to login</span>
                </RouterLink>
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
