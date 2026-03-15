<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { $platformApi } from '@/utils/platformApi'

const { t } = useI18n()
const platformAuth = usePlatformAuthStore()

const form = ref({
  first_name: '',
  last_name: '',
  email: '',
})

const isLoading = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

onMounted(() => {
  const user = platformAuth.user
  if (user) {
    form.value = {
      first_name: user.first_name || '',
      last_name: user.last_name || '',
      email: user.email || '',
    }
  }
})

const handleSave = async () => {
  isLoading.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    const data = await $platformApi('/me/profile', {
      method: 'PUT',
      body: form.value,
    })

    platformAuth._persistUser(data.user)
    successMessage.value = t('platformAccount.profileUpdated')
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('platformAccount.failedToUpdateProfile')
  }
  finally {
    isLoading.value = false
  }
}
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard :title="t('platformAccount.profileInfo')">
        <VCardText>
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

          <VForm @submit.prevent="handleSave">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.first_name"
                  :label="t('platformAccount.firstName')"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.last_name"
                  :label="t('platformAccount.lastName')"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.email"
                  :label="t('platformAccount.email')"
                  type="email"
                />
              </VCol>

              <VCol cols="12">
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
  </VRow>
</template>
