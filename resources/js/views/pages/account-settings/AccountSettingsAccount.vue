<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { useAuthStore } from '@/core/stores/auth'
import { $api } from '@/utils/api'

const { t } = useI18n()
const auth = useAuthStore()

const form = ref({
  first_name: auth.user?.first_name || '',
  last_name: auth.user?.last_name || '',
  email: auth.user?.email || '',
})

const dynamicFields = ref([])
const dynamicForm = ref({})
const profileCompleteness = ref({ filled: 0, total: 0, complete: true })

// ADR-169: group fields by category (aligned with MemberProfileForm)
const categories = ['base', 'hr', 'domain']

const fieldsByCategory = computed(() => {
  const result = {}
  for (const cat of categories) {
    result[cat] = dynamicFields.value.filter(f => (f.category || 'base') === cat)
  }

  return result
})

const visibleCategories = computed(() => {
  return categories.filter(cat => fieldsByCategory.value[cat]?.length > 0)
})

const isLoading = ref(false)
const profileLoading = ref(true)
const successMessage = ref('')
const errorMessage = ref('')
const refInputEl = ref()
const avatarPreview = ref(auth.user?.avatar || null)

// Fetch profile with dynamic fields on mount
onMounted(async () => {
  try {
    const data = await $api('/profile')

    form.value.first_name = data.base_fields?.first_name || ''
    form.value.last_name = data.base_fields?.last_name || ''
    form.value.email = data.base_fields?.email || ''
    avatarPreview.value = data.base_fields?.avatar || null
    dynamicFields.value = data.dynamic_fields || []
    profileCompleteness.value = data.profile_completeness || { filled: 0, total: 0, complete: true }

    // Build dynamic form from resolved fields (keyed by code)
    const df = {}
    for (const field of data.dynamic_fields || []) {
      df[field.code] = field.value ?? null
    }
    dynamicForm.value = df
  }
  finally {
    profileLoading.value = false
  }
})

const handleSave = async () => {
  isLoading.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    const data = await $api('/profile', {
      method: 'PUT',
      body: {
        first_name: form.value.first_name,
        last_name: form.value.last_name,
        dynamic_fields: { ...dynamicForm.value },
      },
    })

    auth._persistUser(data.base_fields)
    successMessage.value = t('accountSettings.profileUpdated')

    // Refresh dynamic fields from response
    dynamicFields.value = data.dynamic_fields || []
    profileCompleteness.value = data.profile_completeness || { filled: 0, total: 0, complete: true }
    const df = {}
    for (const field of data.dynamic_fields || []) {
      df[field.code] = field.value ?? null
    }
    dynamicForm.value = df
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('accountSettings.failedToUpdate')
  }
  finally {
    isLoading.value = false
  }
}

const changeAvatar = async file => {
  const { files } = file.target
  if (!files || !files.length)
    return

  // Preview
  const fileReader = new FileReader()

  fileReader.readAsDataURL(files[0])
  fileReader.onload = () => {
    if (typeof fileReader.result === 'string')
      avatarPreview.value = fileReader.result
  }

  // Upload
  const formData = new FormData()

  formData.append('avatar', files[0])

  try {
    const data = await $api('/profile/avatar', {
      method: 'POST',
      body: formData,
    })

    auth._persistUser(data.user)
    successMessage.value = t('accountSettings.avatarUpdated')
  }
  catch {
    errorMessage.value = t('accountSettings.failedToUploadAvatar')
  }
}

const resetForm = () => {
  form.value.first_name = auth.user?.first_name || ''
  form.value.last_name = auth.user?.last_name || ''
  form.value.email = auth.user?.email || ''
}
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardText class="d-flex">
          <VAvatar
            rounded
            size="100"
            class="me-6"
            :color="!avatarPreview ? 'primary' : undefined"
            :variant="!avatarPreview ? 'tonal' : undefined"
          >
            <VImg
              v-if="avatarPreview"
              :src="avatarPreview"
            />
            <VIcon
              v-else
              icon="tabler-user"
              size="48"
            />
          </VAvatar>

          <form class="d-flex flex-column justify-center gap-4">
            <div class="d-flex flex-wrap gap-4">
              <VBtn
                color="primary"
                size="small"
                @click="refInputEl?.click()"
              >
                <VIcon
                  icon="tabler-cloud-upload"
                  class="d-sm-none"
                />
                <span class="d-none d-sm-block">{{ t('accountSettings.uploadNewPhoto') }}</span>
              </VBtn>

              <input
                ref="refInputEl"
                type="file"
                name="file"
                accept=".jpeg,.png,.jpg"
                hidden
                @input="changeAvatar"
              >
            </div>

            <p class="text-body-1 mb-0">
              {{ t('accountSettings.allowedFileTypes') }}
            </p>
          </form>
        </VCardText>

        <VCardText class="pt-2">
          <!-- ADR-168b: profile completeness alert -->
          <VAlert
            v-if="!profileCompleteness.complete && !profileLoading"
            type="warning"
            variant="tonal"
            class="mb-4"
          >
            {{ t('fields.profileIncomplete', { filled: profileCompleteness.filled, total: profileCompleteness.total }) }}
          </VAlert>

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

          <VForm
            class="mt-3"
            @submit.prevent="handleSave"
          >
            <VRow>
              <VCol
                md="6"
                cols="12"
              >
                <AppTextField
                  v-model="form.first_name"
                  :label="t('members.firstName')"
                  placeholder="John"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.last_name"
                  :label="t('members.lastName')"
                  placeholder="Doe"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.email"
                  :label="t('common.email')"
                  placeholder="johndoe@email.com"
                  type="email"
                  disabled
                />
              </VCol>

              <!-- ADR-169: Dynamic fields grouped by category (aligned with MemberProfileForm) -->
              <template v-if="dynamicFields.length && !profileLoading">
                <template
                  v-for="cat in visibleCategories"
                  :key="cat"
                >
                  <VCol cols="12">
                    <VDivider />
                  </VCol>
                  <VCol cols="12">
                    <h6 class="text-h6">
                      {{ t(`fields.category.${cat}`) }}
                    </h6>
                  </VCol>
                  <DynamicFormRenderer
                    v-model="dynamicForm"
                    :fields="fieldsByCategory[cat]"
                  />
                </template>
              </template>

              <VCol
                cols="12"
                class="d-flex flex-wrap gap-4"
              >
                <VBtn
                  type="submit"
                  :loading="isLoading"
                >
                  {{ t('common.saveChanges') }}
                </VBtn>

                <VBtn
                  color="secondary"
                  variant="tonal"
                  type="reset"
                  @click.prevent="resetForm"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
