<script setup>
const { t } = useI18n()

defineProps({
  errorMessage: { type: String, default: '' },
  errors: { type: Object, default: () => ({}) },
})

const firstName = defineModel('firstName', { type: String })
const lastName = defineModel('lastName', { type: String })
const email = defineModel('email', { type: String })
const password = defineModel('password', { type: String })

const emit = defineEmits(['clearError', 'emailEdited'])

const isPasswordVisible = ref(false)
</script>

<template>
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
    @click:close="emit('clearError')"
  >
    {{ errorMessage }}
  </VAlert>

  <VRow>
    <VCol
      cols="12"
      md="6"
    >
      <AppTextField
        v-model="firstName"
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
        v-model="lastName"
        :label="t('register.lastName')"
        placeholder="Doe"
        :error-messages="errors.last_name"
      />
    </VCol>

    <VCol cols="12">
      <AppTextField
        v-model="email"
        :label="t('auth.email')"
        type="email"
        placeholder="johndoe@email.com"
        :error-messages="errors.email"
        @input="emit('emailEdited')"
      />
    </VCol>

    <VCol cols="12">
      <AppTextField
        v-model="password"
        :label="t('auth.password')"
        placeholder="············"
        :type="isPasswordVisible ? 'text' : 'password'"
        autocomplete="new-password"
        :append-inner-icon="isPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
        :error-messages="errors.password"
        @click:append-inner="isPasswordVisible = !isPasswordVisible"
      />

      <div class="mt-2">
        <p class="text-caption text-medium-emphasis mb-1">
          {{ t('accountSettings.passwordRequirements') }}
        </p>
        <ul class="text-caption text-medium-emphasis ps-4" style="list-style: none;">
          <li :class="password?.length >= 8 ? 'text-success' : ''">
            <VIcon
              :icon="password?.length >= 8 ? 'tabler-check' : 'tabler-point'"
              size="14"
              class="me-1"
            />
            {{ t('accountSettings.minCharsLong') }}
          </li>
          <li :class="/[A-Z]/.test(password || '') ? 'text-success' : ''">
            <VIcon
              :icon="/[A-Z]/.test(password || '') ? 'tabler-check' : 'tabler-point'"
              size="14"
              class="me-1"
            />
            {{ t('accountSettings.requireUppercase') }}
          </li>
          <li :class="/[a-z]/.test(password || '') ? 'text-success' : ''">
            <VIcon
              :icon="/[a-z]/.test(password || '') ? 'tabler-check' : 'tabler-point'"
              size="14"
              class="me-1"
            />
            {{ t('accountSettings.requireLowercase') }}
          </li>
          <li :class="/[0-9]/.test(password || '') ? 'text-success' : ''">
            <VIcon
              :icon="/[0-9]/.test(password || '') ? 'tabler-check' : 'tabler-point'"
              size="14"
              class="me-1"
            />
            {{ t('accountSettings.requireNumber') }}
          </li>
          <li :class="/[^A-Za-z0-9]/.test(password || '') ? 'text-success' : ''">
            <VIcon
              :icon="/[^A-Za-z0-9]/.test(password || '') ? 'tabler-check' : 'tabler-point'"
              size="14"
              class="me-1"
            />
            {{ t('accountSettings.requireSymbol') }}
          </li>
        </ul>
      </div>
    </VCol>
  </VRow>
</template>
