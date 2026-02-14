<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { usePlatformStore } from '@/core/stores/platform'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_platform_users',
  },
})

const route = useRoute()
const router = useRouter()
const platformStore = usePlatformStore()
const platformAuthStore = usePlatformAuthStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const activeTab = ref('overview')
const baseFields = ref({})
const dynamicFields = ref([])
const dynamicForm = ref({})
const savingOverview = ref(false)
const savingDynamic = ref(false)

// Overview form
const form = ref({
  first_name: '',
  last_name: '',
  email: '',
  roles: [],
})

// Credential state
const showSetPasswordFields = ref(false)
const setPasswordForm = ref({ password: '', password_confirmation: '' })
const setPasswordLoading = ref(false)
const resetPasswordLoading = ref(false)
const isConfirmDialogVisible = ref(false)

// Permission + guards
const canManageCredentials = computed(() =>
  platformAuthStore.hasPermission('manage_platform_user_credentials'),
)

const isSuperAdmin = computed(() =>
  baseFields.value.roles?.some(r => r.key === 'super_admin'),
)

const isSelf = computed(() =>
  baseFields.value.id === platformAuthStore.user?.id,
)

const showCredentials = computed(() =>
  canManageCredentials.value && !isSuperAdmin.value && !isSelf.value,
)

// Roles options
const roleOptions = computed(() =>
  platformStore.roles.map(r => ({ title: r.name, value: r.id })),
)

onMounted(async () => {
  try {
    const [profile] = await Promise.all([
      platformStore.fetchPlatformUserProfile(route.params.id),
      platformStore.fetchRoles(),
    ])

    applyProfile(profile)
  }
  catch {
    toast('Failed to load user profile.', 'error')
    router.push('/platform/users')
  }
  finally {
    isLoading.value = false
  }
})

const applyProfile = profile => {
  baseFields.value = profile.base_fields || {}
  dynamicFields.value = profile.dynamic_fields || []

  form.value.first_name = baseFields.value.first_name || ''
  form.value.last_name = baseFields.value.last_name || ''
  form.value.email = baseFields.value.email || ''
  form.value.roles = baseFields.value.roles?.map(r => r.id) || []

  const df = {}
  for (const field of dynamicFields.value) {
    df[field.code] = field.value ?? null
  }
  dynamicForm.value = df
}

const handleOverviewSave = async () => {
  savingOverview.value = true

  try {
    const data = await platformStore.updatePlatformUser(route.params.id, {
      first_name: form.value.first_name,
      last_name: form.value.last_name,
      email: form.value.email,
      roles: form.value.roles,
    })

    toast(data.message, 'success')

    // Re-fetch full profile to get updated ReadModel
    const profile = await platformStore.fetchPlatformUserProfile(route.params.id)

    applyProfile(profile)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update user.', 'error')
  }
  finally {
    savingOverview.value = false
  }
}

const handleDynamicSave = async () => {
  savingDynamic.value = true

  try {
    const data = await platformStore.updatePlatformUser(route.params.id, {
      dynamic_fields: { ...dynamicForm.value },
    })

    toast(data.message, 'success')

    const profile = await platformStore.fetchPlatformUserProfile(route.params.id)

    applyProfile(profile)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update custom fields.', 'error')
  }
  finally {
    savingDynamic.value = false
  }
}

const confirmForceReset = () => {
  isConfirmDialogVisible.value = true
}

const handleConfirmReset = async confirmed => {
  isConfirmDialogVisible.value = false
  if (!confirmed) return

  resetPasswordLoading.value = true

  try {
    const data = await platformStore.resetPlatformUserPassword(route.params.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to send reset email.', 'error')
  }
  finally {
    resetPasswordLoading.value = false
  }
}

const handleSetPassword = async () => {
  setPasswordLoading.value = true

  try {
    const data = await platformStore.setPlatformUserPassword(route.params.id, {
      password: setPasswordForm.value.password,
      password_confirmation: setPasswordForm.value.password_confirmation,
    })

    toast(data.message, 'success')
    showSetPasswordFields.value = false
    setPasswordForm.value = { password: '', password_confirmation: '' }

    // Re-fetch to update status
    const profile = await platformStore.fetchPlatformUserProfile(route.params.id)

    applyProfile(profile)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to set password.', 'error')
  }
  finally {
    setPasswordLoading.value = false
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center gap-x-4 mb-6">
      <VBtn
        icon
        variant="text"
        size="small"
        @click="router.push('/platform/users')"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>

      <template v-if="!isLoading && baseFields.id">
        <VAvatar
          size="48"
          :color="isSuperAdmin ? 'error' : 'secondary'"
          variant="tonal"
        >
          <span class="text-lg">{{ baseFields.first_name?.charAt(0)?.toUpperCase() }}</span>
        </VAvatar>
        <div>
          <h5 class="text-h5">
            {{ baseFields.display_name }}
          </h5>
          <div class="d-flex align-center gap-2">
            <span class="text-body-2 text-disabled">{{ baseFields.email }}</span>
            <VChip
              v-for="role in baseFields.roles"
              :key="role.key"
              :color="role.key === 'super_admin' ? 'error' : 'primary'"
              size="x-small"
            >
              {{ role.name }}
            </VChip>
            <VChip
              :color="baseFields.status === 'active' ? 'success' : 'warning'"
              size="x-small"
            >
              {{ baseFields.status === 'active' ? 'Active' : 'Invited' }}
            </VChip>
          </div>
        </div>
      </template>
    </div>

    <!-- Loading -->
    <VProgressLinear
      v-if="isLoading"
      indeterminate
    />

    <!-- Tabs -->
    <template v-if="!isLoading">
      <VTabs
        v-model="activeTab"
        class="mb-6"
      >
        <VTab value="overview">
          <VIcon
            icon="tabler-user"
            class="me-2"
          />
          Overview
        </VTab>
        <VTab
          v-if="dynamicFields.length"
          value="custom-fields"
        >
          <VIcon
            icon="tabler-forms"
            class="me-2"
          />
          Custom Fields
        </VTab>
        <VTab
          v-if="showCredentials"
          value="credentials"
        >
          <VIcon
            icon="tabler-key"
            class="me-2"
          />
          Credentials
        </VTab>
      </VTabs>

      <VWindow v-model="activeTab">
        <!-- Tab: Overview -->
        <VWindowItem value="overview">
          <VCard>
            <VCardText>
              <VForm @submit.prevent="handleOverviewSave">
                <VRow>
                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppTextField
                      v-model="form.first_name"
                      label="First Name"
                      placeholder="First Name"
                    />
                  </VCol>
                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppTextField
                      v-model="form.last_name"
                      label="Last Name"
                      placeholder="Last Name"
                    />
                  </VCol>
                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppTextField
                      v-model="form.email"
                      label="Email"
                      type="email"
                      placeholder="Email"
                    />
                  </VCol>
                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppSelect
                      v-model="form.roles"
                      :items="roleOptions"
                      label="Roles"
                      placeholder="Select roles"
                      multiple
                      chips
                      closable-chips
                    />
                  </VCol>
                  <VCol cols="12">
                    <VBtn
                      type="submit"
                      :loading="savingOverview"
                    >
                      Save changes
                    </VBtn>
                  </VCol>
                </VRow>
              </VForm>
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- Tab: Custom Fields -->
        <VWindowItem value="custom-fields">
          <VCard>
            <VCardText>
              <VForm @submit.prevent="handleDynamicSave">
                <VRow>
                  <DynamicFormRenderer
                    v-model="dynamicForm"
                    :fields="dynamicFields"
                  />
                  <VCol cols="12">
                    <VBtn
                      type="submit"
                      :loading="savingDynamic"
                    >
                      Save custom fields
                    </VBtn>
                  </VCol>
                </VRow>
              </VForm>
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- Tab: Credentials -->
        <VWindowItem value="credentials">
          <VCard>
            <VCardText>
              <div class="d-flex flex-column gap-4">
                <!-- Force reset -->
                <div>
                  <h6 class="text-h6 mb-2">
                    Force Password Reset
                  </h6>
                  <p class="text-body-2 text-disabled mb-3">
                    Send a password reset email to this user. Any previous reset tokens will be invalidated.
                  </p>
                  <VBtn
                    prepend-icon="tabler-mail-forward"
                    variant="outlined"
                    color="warning"
                    :loading="resetPasswordLoading"
                    @click="confirmForceReset"
                  >
                    Send Reset Email
                  </VBtn>
                </div>

                <VDivider />

                <!-- Set password -->
                <div>
                  <h6 class="text-h6 mb-2">
                    Set Password Manually
                  </h6>
                  <p class="text-body-2 text-disabled mb-3">
                    Override this user's password directly.
                  </p>

                  <VBtn
                    v-if="!showSetPasswordFields"
                    prepend-icon="tabler-key"
                    variant="outlined"
                    color="info"
                    @click="showSetPasswordFields = true"
                  >
                    Set Password
                  </VBtn>

                  <template v-if="showSetPasswordFields">
                    <VRow>
                      <VCol
                        cols="12"
                        md="6"
                      >
                        <AppTextField
                          v-model="setPasswordForm.password"
                          label="New Password"
                          type="password"
                          placeholder="Min 8 characters"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        md="6"
                      >
                        <AppTextField
                          v-model="setPasswordForm.password_confirmation"
                          label="Confirm Password"
                          type="password"
                          placeholder="Repeat password"
                        />
                      </VCol>
                      <VCol cols="12">
                        <div class="d-flex gap-2">
                          <VBtn
                            color="info"
                            :loading="setPasswordLoading"
                            @click="handleSetPassword"
                          >
                            Save Password
                          </VBtn>
                          <VBtn
                            variant="tonal"
                            color="secondary"
                            @click="showSetPasswordFields = false; setPasswordForm = { password: '', password_confirmation: '' }"
                          >
                            Cancel
                          </VBtn>
                        </div>
                      </VCol>
                    </VRow>
                  </template>
                </div>

                <!-- Guards info -->
                <VAlert
                  v-if="isSuperAdmin"
                  type="warning"
                  variant="tonal"
                  density="compact"
                >
                  Super admin credentials cannot be modified from this page.
                </VAlert>
                <VAlert
                  v-if="isSelf"
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  Use your account settings to change your own credentials.
                </VAlert>
              </div>
            </VCardText>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>

    <!-- Confirm Dialog for Force Reset -->
    <VDialog
      v-model="isConfirmDialogVisible"
      max-width="500"
    >
      <VCard class="text-center px-10 py-6">
        <VCardText>
          <VBtn
            icon
            variant="outlined"
            color="warning"
            class="my-4"
            style="block-size: 88px; inline-size: 88px; pointer-events: none;"
          >
            <span class="text-5xl">!</span>
          </VBtn>

          <h6 class="text-lg font-weight-medium">
            Send a password reset email to {{ baseFields.display_name }}?
          </h6>
          <p class="text-body-2 text-disabled mt-2">
            This will invalidate any previous reset tokens.
          </p>
        </VCardText>

        <VCardText class="d-flex align-center justify-center gap-2">
          <VBtn
            variant="elevated"
            color="warning"
            @click="handleConfirmReset(true)"
          >
            Confirm
          </VBtn>

          <VBtn
            color="secondary"
            variant="tonal"
            @click="handleConfirmReset(false)"
          >
            Cancel
          </VBtn>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>
