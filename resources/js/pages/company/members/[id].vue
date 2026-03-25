<script setup>
definePage({ meta: { module: 'core.members', surface: 'structure' } })

import { useAuthStore } from '@/core/stores/auth'
import { useMembersStore } from '@/modules/company/members/members.store'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import MemberProfileForm from '@/company/components/MemberProfileForm.vue'
import MemberDocumentsWorkflowPanel from '@/views/pages/company-members/MemberDocumentsWorkflowPanel.vue'
import CreateDocumentTypeDrawer from '@/company/views/CreateDocumentTypeDrawer.vue'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const membersStore = useMembersStore()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()

const member = ref(null)
const baseFields = ref({})
const dynamicFields = ref([])
const dynamicForm = ref({})
const profileCompleteness = ref({ filled: 0, total: 0, complete: true })
const isLoading = ref(true)
const activeTab = ref('overview')
const fieldLoading = ref(false)

// Credential state
const showSetPasswordFields = ref(false)
const setPasswordForm = ref({ password: '', password_confirmation: '' })
const setPasswordLoading = ref(false)
const resetPasswordLoading = ref(false)
const isConfirmDialogVisible = ref(false)

const canEdit = computed(() => auth.hasPermission('members.manage'))

// ADR-388: Inline custom document type creation from member page
const isCreateDocTypeDrawerOpen = ref(false)

const isSelf = computed(() =>
  baseFields.value?.id === auth.user?.id,
)

const showCredentials = computed(() =>
  auth.hasPermission('members.credentials') && !member.value?._isProtected && !isSelf.value,
)

const applyProfile = data => {
  member.value = data.member
  baseFields.value = data.base_fields || {}
  dynamicFields.value = data.dynamic_fields || []
  profileCompleteness.value = data.profile_completeness || { filled: 0, total: 0, complete: true }

  const df = {}
  for (const field of dynamicFields.value) {
    df[field.code] = field.value ?? null
  }
  dynamicForm.value = df
}

onMounted(async () => {
  try {
    const [data] = await Promise.all([
      membersStore.fetchMemberProfile(route.params.id),
      settingsStore.fetchCompanyRoles({ silent: true }).catch(() => {}),
    ])

    applyProfile(data)
  }
  catch {
    toast(t('members.failedToLoadProfile'), 'error')
    router.push('/company/members')
  }
  finally {
    isLoading.value = false
  }
})

const handleOverviewSave = async payload => {
  try {
    const data = await membersStore.updateMemberProfile(route.params.id, payload)

    applyProfile(data)
    toast(t('members.profileUpdated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('members.failedToUpdateProfile'), 'error')
  }
}

// ADR-164: When role changes, refetch dynamic fields for the new role
const handleRoleChange = async roleId => {
  const role = settingsStore.roles.find(r => r.id === roleId)
  const roleKey = role?.key || null

  fieldLoading.value = true

  try {
    const fields = await membersStore.fetchMemberFields(route.params.id, roleKey)

    dynamicFields.value = fields

    // Re-sync dynamicForm, preserving existing values where possible
    const df = {}
    for (const field of fields) {
      df[field.code] = dynamicForm.value[field.code] ?? field.value ?? null
    }
    dynamicForm.value = df
  }
  catch {
    toast(t('members.failedToLoadFields'), 'error')
  }
  finally {
    fieldLoading.value = false
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
    const data = await membersStore.resetMemberPassword(route.params.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('credentials.failedToSendReset'), 'error')
  }
  finally {
    resetPasswordLoading.value = false
  }
}

const handleSetPassword = async () => {
  setPasswordLoading.value = true

  try {
    const data = await membersStore.setMemberPassword(route.params.id, {
      password: setPasswordForm.value.password,
      password_confirmation: setPasswordForm.value.password_confirmation,
    })

    toast(data.message, 'success')
    showSetPasswordFields.value = false
    setPasswordForm.value = { password: '', password_confirmation: '' }

    // Re-fetch to update status
    const profile = await membersStore.fetchMemberProfile(route.params.id)

    applyProfile(profile)
  }
  catch (error) {
    toast(error?.data?.message || t('credentials.failedToSetPassword'), 'error')
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
              :color="member.role === 'owner' ? 'primary' : member.company_role?.key === 'admin' ? 'warning' : 'info'"
              size="x-small"
              class="text-capitalize"
            >
              {{ member.company_role?.name || member.role }}
            </VChip>
            <VChip
              :color="baseFields.status === 'active' ? 'success' : 'warning'"
              size="x-small"
            >
              {{ baseFields.status === 'active' ? t('members.activeStatus') : t('members.invitedStatus') }}
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
          {{ t('members.overview') }}
        </VTab>
        <VTab value="documents">
          <VIcon
            icon="tabler-file-text"
            class="me-2"
          />
          {{ t('documents.title') }}
        </VTab>
        <VTab
          v-if="showCredentials"
          value="credentials"
        >
          <VIcon
            icon="tabler-key"
            class="me-2"
          />
          {{ t('credentials.title') }}
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
                :dynamic-fields="dynamicFields"
                :profile-completeness="profileCompleteness"
                :editable="canEdit"
                :loading="fieldLoading"
                :company-roles="settingsStore.roles"
                @save="handleOverviewSave"
                @role-change="handleRoleChange"
              />
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- Tab: Documents (ADR-178 / ADR-388) -->
        <VWindowItem value="documents">
          <MemberDocumentsWorkflowPanel
            :member-id="route.params.id"
            :can-edit="canEdit"
            @create-custom-type="isCreateDocTypeDrawerOpen = true"
          />
        </VWindowItem>

        <!-- Tab: Credentials -->
        <VWindowItem value="credentials">
          <VCard>
            <VCardText>
              <div class="d-flex flex-column gap-4">
                <!-- Force reset -->
                <div>
                  <h6 class="text-h6 mb-2">
                    {{ t('credentials.forceReset') }}
                  </h6>
                  <p class="text-body-2 text-disabled mb-3">
                    {{ t('credentials.forceResetDescription') }}
                  </p>
                  <VBtn
                    prepend-icon="tabler-mail-forward"
                    variant="outlined"
                    color="warning"
                    :loading="resetPasswordLoading"
                    @click="confirmForceReset"
                  >
                    {{ t('credentials.sendResetEmail') }}
                  </VBtn>
                </div>

                <VDivider />

                <!-- Set password -->
                <div>
                  <h6 class="text-h6 mb-2">
                    {{ t('credentials.setPasswordManually') }}
                  </h6>
                  <p class="text-body-2 text-disabled mb-3">
                    {{ t('credentials.setPasswordDescription') }}
                  </p>

                  <VBtn
                    v-if="!showSetPasswordFields"
                    prepend-icon="tabler-key"
                    variant="outlined"
                    color="info"
                    @click="showSetPasswordFields = true"
                  >
                    {{ t('credentials.setPassword') }}
                  </VBtn>

                  <template v-if="showSetPasswordFields">
                    <VRow>
                      <VCol
                        cols="12"
                        md="6"
                      >
                        <AppTextField
                          v-model="setPasswordForm.password"
                          :label="t('credentials.newPassword')"
                          type="password"
                          :placeholder="t('credentials.minChars')"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        md="6"
                      >
                        <AppTextField
                          v-model="setPasswordForm.password_confirmation"
                          :label="t('credentials.confirmPassword')"
                          type="password"
                          :placeholder="t('credentials.repeatPassword')"
                        />
                      </VCol>
                      <VCol cols="12">
                        <div class="d-flex gap-2">
                          <VBtn
                            color="info"
                            :loading="setPasswordLoading"
                            @click="handleSetPassword"
                          >
                            {{ t('credentials.savePassword') }}
                          </VBtn>
                          <VBtn
                            variant="tonal"
                            color="secondary"
                            @click="showSetPasswordFields = false; setPasswordForm = { password: '', password_confirmation: '' }"
                          >
                            {{ t('common.cancel') }}
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

    <!-- ADR-388: Inline custom document type creation -->
    <CreateDocumentTypeDrawer
      v-model:is-drawer-open="isCreateDocTypeDrawerOpen"
    />

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
            {{ t('credentials.confirmResetTitle', { name: baseFields.display_name }) }}
          </h6>
          <p class="text-body-2 text-disabled mt-2">
            {{ t('credentials.confirmResetMessage') }}
          </p>
        </VCardText>

        <VCardText class="d-flex align-center justify-center gap-2">
          <VBtn
            variant="elevated"
            color="warning"
            @click="handleConfirmReset(true)"
          >
            {{ t('credentials.confirm') }}
          </VBtn>

          <VBtn
            color="secondary"
            variant="tonal"
            @click="handleConfirmReset(false)"
          >
            {{ t('common.cancel') }}
          </VBtn>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>
