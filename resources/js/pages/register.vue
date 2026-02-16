<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useRuntimeStore } from '@/core/runtime/runtime'
import { useGenerateImageVariant } from '@core/composable/useGenerateImageVariant'
import authV2RegisterIllustrationBorderedDark from '@images/pages/auth-v2-register-illustration-bordered-dark.png'
import authV2RegisterIllustrationBorderedLight from '@images/pages/auth-v2-register-illustration-bordered-light.png'
import authV2RegisterIllustrationDark from '@images/pages/auth-v2-register-illustration-dark.png'
import authV2RegisterIllustrationLight from '@images/pages/auth-v2-register-illustration-light.png'
import authV2MaskDark from '@images/pages/misc-mask-dark.png'
import authV2MaskLight from '@images/pages/misc-mask-light.png'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

definePage({
  meta: {
    layout: 'blank',
    unauthenticatedOnly: true,
  },
})

const auth = useAuthStore()
const runtime = useRuntimeStore()
const router = useRouter()

const form = ref({
  first_name: '',
  last_name: '',
  email: '',
  password: '',
  password_confirmation: '',
  company_name: '',
})

const isPasswordVisible = ref(false)
const isLoading = ref(false)
const errorMessage = ref('')
const errors = ref({})

const handleRegister = async () => {
  isLoading.value = true
  errorMessage.value = ''
  errors.value = {}

  try {
    await auth.register({
      first_name: form.value.first_name,
      last_name: form.value.last_name,
      email: form.value.email,
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
      company_name: form.value.company_name,
    })

    // Reset runtime to cold — the guard will boot('company') on redirect
    runtime.teardown()

    await router.push('/')
  }
  catch (error) {
    if (error?.data?.errors)
      errors.value = error.data.errors

    errorMessage.value = error?.data?.message || 'Registration failed.'
  }
  finally {
    isLoading.value = false
  }
}

const imageVariant = useGenerateImageVariant(authV2RegisterIllustrationLight, authV2RegisterIllustrationDark, authV2RegisterIllustrationBorderedLight, authV2RegisterIllustrationBorderedDark, true)
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
          style="padding-inline: 100px;"
        >
          <VImg
            max-width="500"
            :src="imageVariant"
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
        class="mt-12 pa-6"
      >
        <VCardText>
          <h4 class="text-h4 mb-1">
            Get started with {{ themeConfig.app.title }}
          </h4>
          <p class="mb-0">
            Create your account and your company
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

          <VForm @submit.prevent="handleRegister">
            <VRow>
              <!-- First Name / Last Name -->
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.first_name"
                  autofocus
                  label="First Name"
                  placeholder="John"
                  :error-messages="errors.first_name"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.last_name"
                  label="Last Name"
                  placeholder="Doe"
                  :error-messages="errors.last_name"
                />
              </VCol>

              <!-- Email -->
              <VCol cols="12">
                <AppTextField
                  v-model="form.email"
                  label="Email"
                  type="email"
                  placeholder="johndoe@email.com"
                  :error-messages="errors.email"
                />
              </VCol>

              <!-- Company Name -->
              <VCol cols="12">
                <AppTextField
                  v-model="form.company_name"
                  label="Company Name"
                  placeholder="My Company"
                  :error-messages="errors.company_name"
                />
              </VCol>

              <!-- Password -->
              <VCol cols="12">
                <AppTextField
                  v-model="form.password"
                  label="Password"
                  placeholder="············"
                  :type="isPasswordVisible ? 'text' : 'password'"
                  autocomplete="new-password"
                  :append-inner-icon="isPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  :error-messages="errors.password"
                  @click:append-inner="isPasswordVisible = !isPasswordVisible"
                />
              </VCol>

              <!-- Confirm Password -->
              <VCol cols="12">
                <AppTextField
                  v-model="form.password_confirmation"
                  label="Confirm Password"
                  placeholder="············"
                  :type="isPasswordVisible ? 'text' : 'password'"
                  autocomplete="new-password"
                />

                <VBtn
                  block
                  type="submit"
                  class="mt-6"
                  :loading="isLoading"
                >
                  Sign up
                </VBtn>
              </VCol>

              <!-- Sign in link -->
              <VCol
                cols="12"
                class="text-center text-base"
              >
                <span class="d-inline-block">Already have an account?</span>
                <RouterLink
                  class="text-primary ms-1 d-inline-block"
                  to="/login"
                >
                  Sign in instead
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
