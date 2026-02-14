<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyStore } from '@/core/stores/company'
import MemberProfileForm from '@/company/components/MemberProfileForm.vue'
import { useAppToast } from '@/composables/useAppToast'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const companyStore = useCompanyStore()
const { toast } = useAppToast()

const member = ref(null)
const baseFields = ref({})
const dynamicFields = ref([])
const dynamicForm = ref({})
const isLoading = ref(true)
const activeTab = ref('overview')
const savingDynamic = ref(false)

// Credential state
const showSetPasswordFields = ref(false)
const setPasswordForm = ref({ password: '', password_confirmation: '' })
const setPasswordLoading = ref(false)
const resetPasswordLoading = ref(false)
const isConfirmDialogVisible = ref(false)

const canEdit = computed(() => {
  const role = auth.currentCompany?.role
  return role === 'owner' || role === 'admin'
})

const isSelf = computed(() =>
  baseFields.value?.id === auth.user?.id,
)

const showCredentials = computed(() =>
  canEdit.value && member.value?.role !== 'owner' && !isSelf.value,
)

const applyProfile = data => {
  member.value = data.member
  baseFields.value = data.base_fields || {}
  dynamicFields.value = data.dynamic_fields || []

  const df = {}
  for (const field of dynamicFields.value) {
    df[field.code] = field.value ?? null
  }
  dynamicForm.value = df
}

onMounted(async () => {
  try {
    const data = await companyStore.fetchMemberProfile(route.params.id)

    applyProfile(data)
  }
  catch {
    toast('Failed to load member profile.', 'error')
    router.push('/company/members')
  }
  finally {
    isLoading.value = false
  }
})

const handleOverviewSave = async payload => {
  try {
    const data = await companyStore.updateMemberProfile(route.params.id, payload)

    applyProfile(data)
    toast('Member profile updated.', 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update profile.', 'error')
  }
}

const handleDynamicSave = async () => {
  savingDynamic.value = true

  try {
    const data = await companyStore.updateMemberProfile(route.params.id, {
      dynamic_fields: { ...dynamicForm.value },
    })

    applyProfile(data)
    toast('Custom fields updated.', 'success')
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
    const data = await companyStore.resetMemberPassword(route.params.id)

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
    const data = await companyStore.setMemberPassword(route.params.id, {
      password: setPasswordForm.value.password,
      password_confirmation: setPasswordForm.value.password_confirmation,
    })

    toast(data.message, 'success')
    showSetPasswordFields.value = false
    setPasswordForm.value = { password: '', password_confirmation: '' }

    // Re-fetch to update status
    const profile = await companyStore.fetchMemberProfile(route.params.id)

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
        @click="router.push('/company/members')"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>

      <template v-if="!isLoading && baseFields">
        <VAvatar
          size="48"
          :color="!baseFields.avatar ? 'primary' : undefined"
          :variant="!baseFields.avatar ? 'tonal' : undefined"
        >
          <VImg
            v-if="baseFields.avatar"
            :src="baseFields.avatar"
          />
          <VIcon
            v-else
            icon="tabler-user"
            size="24"
          />
        </VAvatar>
        <div>
          <h5 class="text-h5">
            {{ baseFields.display_name }}
          </h5>
          <div class="d-flex align-center gap-2">
            <span class="text-body-2 text-disabled">{{ baseFields.email }}</span>
            <VChip
              v-if="member"
              :color="member.role === 'owner' ? 'primary' : member.role === 'admin' ? 'warning' : 'info'"
              size="x-small"
              class="text-capitalize"
            >
              {{ member.role }}
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
              <MemberProfileForm
                :member="member"
                :base-fields="baseFields"
                :dynamic-fields="[]"
                :editable="canEdit"
                @save="handleOverviewSave"
              />
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
