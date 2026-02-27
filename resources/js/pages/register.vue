<script setup>
const { t } = useI18n()

import { formatMoney } from '@/utils/money'
import { useAuthStore } from '@/core/stores/auth'
import { useRuntimeStore } from '@/core/runtime/runtime'
import { usePublicPlans } from '@/composables/usePublicPlans'
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
    public: true,
    unauthenticatedOnly: true,
  },
})

usePublicTheme()

const auth = useAuthStore()
const runtime = useRuntimeStore()
const router = useRouter()

// ─── Wizard state ──────────────────────────────────────────
const currentStep = ref(0)

const stepItems = computed(() => [
  { title: t('register.industryStep'), subtitle: t('register.industryStepSubtitle'), icon: 'tabler-briefcase' },
  { title: t('register.planStep'), subtitle: t('register.planStepSubtitle'), icon: 'tabler-credit-card' },
  { title: t('register.accountStep'), subtitle: t('register.accountStepSubtitle'), icon: 'tabler-user' },
])

// ─── Public plans data ─────────────────────────────────────
const { plans, jobdomains, loading: plansLoading, previewModules, fetchPlans, fetchPreview } = usePublicPlans()

onMounted(() => {
  fetchPlans()
})

// ─── Form state ────────────────────────────────────────────
const selectedJobdomain = ref(null)
const selectedPlan = ref('starter')
const annualToggle = ref(true)

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

// ─── Preview modules when plan/jobdomain change ────────────
watch([selectedJobdomain, selectedPlan], ([jd, plan]) => {
  if (jd && plan) {
    fetchPreview(jd, plan)
  }
})

// ─── Submit ────────────────────────────────────────────────
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
      jobdomain_key: selectedJobdomain.value || undefined,
      plan_key: selectedPlan.value || undefined,
    })

    runtime.teardown()
    await router.push('/dashboard')
  }
  catch (error) {
    if (error?.data?.errors)
      errors.value = error.data.errors

    errorMessage.value = error?.data?.message || t('auth.registrationFailed')
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
      md="4"
      class="d-none d-md-flex"
    >
      <div class="position-relative bg-background w-100 me-0">
        <div
          class="d-flex align-center justify-center w-100 h-100"
          style="padding-inline: 50px;"
        >
          <VImg
            max-width="400"
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
      md="8"
      class="auth-card-v2 d-flex align-center justify-center pa-10"
    >
      <VCard
        flat
        class="mt-12 mt-sm-10"
        :max-width="750"
      >
        <AppStepper
          v-model:current-step="currentStep"
          :items="stepItems"
          :direction="$vuetify.display.smAndUp ? 'horizontal' : 'vertical'"
          icon-size="22"
          class="stepper-icon-step-bg mb-8"
        />

        <VWindow
          v-model="currentStep"
          class="disable-tab-transition"
        >
          <!-- ─── Step 1: Jobdomain ─────────────────────── -->
          <VWindowItem>
            <h4 class="text-h4 mb-1">
              {{ t('register.whatsYourIndustry') }}
            </h4>
            <p class="text-body-1 mb-6">
              {{ t('register.industryDescription') }}
            </p>

            <VSkeletonLoader
              v-if="plansLoading"
              type="card"
            />

            <template v-else>
              <CustomRadiosWithIcon
                v-model:selected-radio="selectedJobdomain"
                :radio-content="jobdomains.map(jd => ({
                  title: jd.label,
                  desc: jd.description || '',
                  value: jd.key,
                  icon: { icon: 'tabler-briefcase', size: '28' },
                }))"
                :grid-column="{ sm: '4', cols: '12' }"
              >
                <template #default="{ item }">
                  <div class="text-center">
                    <VIcon
                      icon="tabler-briefcase"
                      size="36"
                      class="mb-2 text-primary"
                    />
                    <h5 class="text-h5 mb-2">
                      {{ item.title }}
                    </h5>
                    <p class="clamp-text mb-0 text-body-2">
                      {{ item.desc }}
                    </p>
                  </div>
                </template>
              </CustomRadiosWithIcon>

              <p
                v-if="jobdomains.length === 0"
                class="text-body-1 text-disabled"
              >
                {{ t('register.noIndustriesAvailable') }}
              </p>
            </template>
          </VWindowItem>

          <!-- ─── Step 2: Plan ──────────────────────────── -->
          <VWindowItem>
            <h4 class="text-h4 mb-1">
              {{ t('register.choosePlan') }}
            </h4>
            <p class="text-body-1 mb-4">
              {{ t('register.allPlansInclude') }}
            </p>

            <!-- Monthly/Annual toggle -->
            <div class="d-flex font-weight-medium text-body-1 align-center justify-center mb-6">
              <VLabel
                for="plan-toggle"
                class="me-3"
              >
                {{ t('common.monthly') }}
              </VLabel>
              <VSwitch
                id="plan-toggle"
                v-model="annualToggle"
              >
                <template #label>
                  <div class="text-body-1 font-weight-medium">
                    {{ t('common.annually') }}
                  </div>
                </template>
              </VSwitch>
            </div>

            <VRow>
              <VCol
                v-for="plan in plans"
                :key="plan.key"
                cols="12"
                sm="4"
              >
                <VCard
                  flat
                  border
                  :class="[
                    'cursor-pointer plan-card',
                    selectedPlan === plan.key ? 'border-primary border-opacity-100' : '',
                  ]"
                  @click="selectedPlan = plan.key"
                >
                  <VCardText
                    style="block-size: 2.5rem;"
                    class="text-end"
                  >
                    <VChip
                      v-if="plan.is_popular"
                      label
                      color="primary"
                      size="small"
                    >
                      {{ t('common.popular') }}
                    </VChip>
                  </VCardText>

                  <VCardText class="text-center">
                    <h5 class="text-h5 mb-1">
                      {{ plan.name }}
                    </h5>
                    <p class="text-body-2 mb-4">
                      {{ plan.description }}
                    </p>

                    <div class="d-flex justify-center align-baseline pb-4">
                      <span class="text-h3 font-weight-medium text-primary">
                        {{ formatMoney(annualToggle ? Math.round(plan.price_yearly / 12 * 100) : plan.price_monthly * 100) }}
                      </span>
                      <span class="text-body-1 font-weight-medium">{{ t('common.perMonth') }}</span>
                    </div>

                    <VList
                      density="compact"
                      class="card-list"
                    >
                      <VListItem
                        v-for="feature in plan.feature_labels"
                        :key="feature"
                      >
                        <template #prepend>
                          <VIcon
                            size="8"
                            icon="tabler-circle-filled"
                            color="rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity))"
                          />
                        </template>
                        <VListItemTitle class="text-body-2">
                          {{ feature }}
                        </VListItemTitle>
                      </VListItem>
                    </VList>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <!-- Module preview -->
            <div
              v-if="previewModules.length > 0"
              class="mt-4"
            >
              <p class="text-body-2 font-weight-medium mb-2">
                {{ t('register.includedModules') }}
              </p>
              <div class="d-flex flex-wrap gap-2">
                <VChip
                  v-for="mod in previewModules"
                  :key="mod.key"
                  size="small"
                  :color="mod.source === 'core' ? 'secondary' : 'primary'"
                >
                  {{ mod.name }}
                </VChip>
              </div>
            </div>
          </VWindowItem>

          <!-- ─── Step 3: Account ───────────────────────── -->
          <VWindowItem>
            <h4 class="text-h4 mb-1">
              {{ t('register.createYourAccount') }}
            </h4>
            <p class="text-body-1 mb-6">
              {{ t('register.enterDetails') }}
            </p>

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
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model="form.first_name"
                    autofocus
                    :label="t('register.firstName')"
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
                    :label="t('register.lastName')"
                    placeholder="Doe"
                    :error-messages="errors.last_name"
                  />
                </VCol>

                <VCol cols="12">
                  <AppTextField
                    v-model="form.email"
                    :label="t('auth.email')"
                    type="email"
                    placeholder="johndoe@email.com"
                    :error-messages="errors.email"
                  />
                </VCol>

                <VCol cols="12">
                  <AppTextField
                    v-model="form.company_name"
                    :label="t('register.companyName')"
                    :placeholder="t('register.companyNamePlaceholder')"
                    :error-messages="errors.company_name"
                  />
                </VCol>

                <VCol cols="12">
                  <AppTextField
                    v-model="form.password"
                    :label="t('auth.password')"
                    placeholder="············"
                    :type="isPasswordVisible ? 'text' : 'password'"
                    autocomplete="new-password"
                    :append-inner-icon="isPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                    :error-messages="errors.password"
                    @click:append-inner="isPasswordVisible = !isPasswordVisible"
                  />
                </VCol>

                <VCol cols="12">
                  <AppTextField
                    v-model="form.password_confirmation"
                    :label="t('auth.confirmPassword')"
                    placeholder="············"
                    :type="isPasswordVisible ? 'text' : 'password'"
                    autocomplete="new-password"
                  />
                </VCol>

                <VCol cols="12">
                  <VBtn
                    block
                    type="submit"
                    :loading="isLoading"
                  >
                    {{ t('register.createAccountBtn') }}
                  </VBtn>
                </VCol>

                <VCol
                  cols="12"
                  class="text-center text-base"
                >
                  <span class="d-inline-block">{{ t('auth.alreadyHaveAccount') }}</span>
                  <RouterLink
                    class="text-primary ms-1 d-inline-block"
                    to="/login"
                  >
                    {{ t('auth.signInInstead') }}
                  </RouterLink>
                </VCol>
              </VRow>
            </VForm>
          </VWindowItem>
        </VWindow>

        <!-- ─── Navigation buttons ───────────────────── -->
        <div class="d-flex flex-wrap justify-space-between gap-x-4 mt-6">
          <VBtn
            color="secondary"
            :disabled="currentStep === 0"
            variant="tonal"
            @click="currentStep--"
          >
            <VIcon
              icon="tabler-arrow-left"
              start
              class="flip-in-rtl"
            />
            {{ t('register.previous') }}
          </VBtn>

          <VBtn
            v-if="currentStep < 2"
            @click="currentStep++"
          >
            {{ currentStep === 0 && !selectedJobdomain ? t('register.skip') : t('register.next') }}
            <VIcon
              icon="tabler-arrow-right"
              end
              class="flip-in-rtl"
            />
          </VBtn>
        </div>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth";

.plan-card {
  transition: border-color 0.2s ease;
}

.card-list {
  --v-card-list-gap: 0.5rem;
}
</style>
